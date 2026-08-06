<?php

/*
 * This file is part of the fabpot/json-rpc-peer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fabpot\JsonRpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Closable;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\InvalidResponseException;
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\Exception\RuntimeException;
use Fabpot\JsonRpc\Exception\UnexpectedValueException;

use function Amp\async;
use function Amp\Future\awaitAll;

/**
 * Minimal bidirectional JSON-RPC 2.0 peer.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcPeer implements Closable, ResponseSenderInterface
{
    /** @var (callable(JsonRpcMessage, RequestResponder|null): mixed)|null */
    private $messageHandler;

    private readonly JsonRpcWriter $writer;
    private readonly DeferredCancellation $connectionCancellation;
    /** @var DeferredFuture<void> */
    private readonly DeferredFuture $onClose;
    private int $nextRequestId = 1;
    private int $nextInboundRequestId = 1;
    private bool $listenerStarted = false;
    private bool $shutdownStarted = false;
    private bool $closed = false;
    private bool $transportClosed = false;

    /** @var array<string, PendingRequest> */
    private array $pendingRequests = [];

    /** @var array<int, Future<mixed>> */
    private array $inboundRequests = [];
    private ?\Throwable $inboundRequestError = null;

    /**
     * Takes ownership of the transport and closes it when close() is called.
     */
    public function __construct(
        private readonly JsonRpcTransportInterface $transport,
        private readonly ?TrafficLoggerInterface $trafficLogger = null,
        private readonly int $maximumConcurrentInboundRequests = 64,
        private readonly int $maximumBatchEntries = 128,
    ) {
        if ($maximumConcurrentInboundRequests < 1) {
            throw new InvalidArgumentException('The maximum number of concurrent inbound requests must be a positive integer.');
        }
        if ($maximumBatchEntries < 1) {
            throw new InvalidArgumentException('The maximum number of JSON-RPC batch entries must be a positive integer.');
        }

        $this->writer = new JsonRpcWriter($transport, $trafficLogger);
        $this->connectionCancellation = new DeferredCancellation();
        $this->onClose = new DeferredFuture();
    }

    /** @internal */
    public function getConnectionCancellation(): Cancellation
    {
        return $this->connectionCancellation->getCancellation();
    }

    /**
     * @internal
     *
     * @param callable(JsonRpcMessage, RequestResponder|null): mixed $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function listen(): void
    {
        if ($this->listenerStarted) {
            throw new RuntimeException('The JSON-RPC listener has already been started.');
        }
        if ($this->shutdownStarted) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $this->listenerStarted = true;
        $error = null;

        try {
            try {
                async($this->listenLoop(...))->await();
            } catch (\Throwable $e) {
                $error = $this->inboundRequestError ?? $e;
            } finally {
                $this->beginShutdown();
            }

            [$inboundErrors] = awaitAll($this->inboundRequests);
            $this->inboundRequests = [];
            $error ??= $this->inboundRequestError;
            if (null === $error) {
                foreach ($inboundErrors as $inboundError) {
                    $error = $inboundError;
                    break;
                }
            }
        } finally {
            $this->writer->close();

            try {
                $this->closeTransport();
            } catch (\Throwable $e) {
                $error ??= $e;
            } finally {
                $this->finishShutdown();
            }
        }

        if (null !== $error) {
            throw $error;
        }
    }

    public function close(): void
    {
        $this->beginShutdown();
        $this->writer->close();

        try {
            $this->closeTransport();
        } finally {
            $this->finishShutdown();
        }
    }

    public function isClosed(): bool
    {
        return $this->shutdownStarted;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose)->ignore();
    }

    private function listenLoop(): void
    {
        while (true) {
            $message = $this->transport->receive();
            if (null === $message) {
                return;
            }

            try {
                $this->processMessage($message);
            } catch (ConnectionClosedException) {
                // the response is undeliverable, keep draining inbound messages
            }
        }
    }

    private function closeTransport(): void
    {
        if ($this->transportClosed) {
            return;
        }

        $this->transportClosed = true;
        $this->transport->close();
    }

    private function beginShutdown(): void
    {
        if ($this->shutdownStarted) {
            return;
        }

        $this->shutdownStarted = true;
        $this->connectionCancellation->cancel();
        $pendingRequests = $this->pendingRequests;
        $this->pendingRequests = [];
        foreach ($pendingRequests as $pendingRequest) {
            $pendingRequest->error(new ConnectionClosedException('The JSON-RPC connection closed before a response was received.'));
        }
    }

    private function finishShutdown(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->onClose->complete();
    }

    private function processMessage(string $message): void
    {
        $this->trafficLogger?->logInbound($message);
        $message = trim($message, " \t\r\n");

        try {
            $decoded = json_decode($message, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->respondError(null, JsonRpcError::PARSE_ERROR, 'Parse error');

            return;
        }

        if (\is_array($decoded)) {
            if (!array_is_list($decoded)) {
                $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');

                return;
            }
            /** @var list<mixed> $decoded */

            $this->handleBatch($decoded);

            return;
        }
        if (!$decoded instanceof \stdClass) {
            $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');

            return;
        }

        /** @var array<string, mixed> $data */
        $data = get_object_vars($decoded);
        if ($this->isResponse($data)) {
            $this->handleResponse($data);
        } else {
            $this->handleRequest($data, $this);
        }
    }

    /**
     * @param array<array-key, mixed>|object|null $params
     *
     * @return Future<mixed>
     */
    public function request(string $method, array|object|null $params = null, ?Cancellation $cancellation = null): Future
    {
        return $this->startRequest($method, $params, $cancellation)->getFuture();
    }

    /**
     * @param array<array-key, mixed>|object|null $params
     */
    public function startRequest(string $method, array|object|null $params = null, ?Cancellation $cancellation = null): OutboundRequest
    {
        if ($this->shutdownStarted) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $cancellation?->throwIfRequested();
        $params = JsonRpcValues::normalizeParams($params);
        $cancellation?->throwIfRequested();
        $id = $this->nextRequestId++;
        $key = $this->requestKey($id);
        $pendingRequest = $this->registerPendingRequest($key, $cancellation);

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];
        if (null !== $params) {
            $payload['params'] = $params;
        }

        try {
            $cancellation?->throwIfRequested();
            $this->writer->write($payload);
        } catch (\Throwable $e) {
            $this->detachPendingRequest($key, $pendingRequest);
            $pendingRequest->abandon($e);

            throw $e;
        }

        return new OutboundRequest($id, $pendingRequest->getFuture());
    }

    /**
     * @return list<Future<mixed>>
     */
    public function batch(BatchRequest|BatchNotification ...$entries): array
    {
        if (!$entries) {
            throw new InvalidArgumentException('A JSON-RPC batch must contain at least one entry.');
        }
        if (\count($entries) > $this->maximumBatchEntries) {
            throw new InvalidArgumentException('The JSON-RPC batch exceeds the configured entry limit.');
        }

        if ($this->shutdownStarted) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $normalizedParams = [];
        foreach ($entries as $index => $entry) {
            if ($entry instanceof BatchRequest) {
                try {
                    $entry->getCancellation()?->throwIfRequested();
                } catch (CancelledException) {
                    $normalizedParams[$index] = null;

                    continue;
                }
            }

            $normalizedParams[$index] = JsonRpcValues::normalizeParams($entry->getParams());
        }

        /** @var list<array{payload: array<string, mixed>}|array{payload: array<string, mixed>, key: string, pendingRequest: PendingRequest, cancellation: Cancellation|null}> $records */
        $records = [];
        /** @var list<array{string|null, PendingRequest}> $createdRequests */
        $createdRequests = [];
        $futures = [];
        foreach ($entries as $index => $entry) {
            $params = $normalizedParams[$index];
            $payload = [
                'jsonrpc' => '2.0',
                'method' => $entry->getMethod(),
            ];
            if (null !== $params) {
                $payload['params'] = $params;
            }

            if (!$entry instanceof BatchRequest) {
                $records[] = ['payload' => $payload];

                continue;
            }

            $cancellation = $entry->getCancellation();
            try {
                $cancellation?->throwIfRequested();
            } catch (CancelledException $e) {
                $pendingRequest = new PendingRequest($cancellation);
                $pendingRequest->error($e);
                $createdRequests[] = [null, $pendingRequest];
                $futures[] = $pendingRequest->getFuture();

                continue;
            }

            $id = $this->nextRequestId++;
            $key = $this->requestKey($id);
            $pendingRequest = $this->registerPendingRequest($key, $cancellation);
            $payload['id'] = $id;
            $records[] = [
                'payload' => $payload,
                'key' => $key,
                'pendingRequest' => $pendingRequest,
                'cancellation' => $cancellation,
            ];
            $createdRequests[] = [$key, $pendingRequest];
            $futures[] = $pendingRequest->getFuture();
        }

        $payloads = [];
        foreach ($records as $record) {
            if (!isset($record['pendingRequest'])) {
                $payloads[] = $record['payload'];

                continue;
            }

            try {
                $record['cancellation']?->throwIfRequested();
            } catch (CancelledException $e) {
                $this->failPendingRequest($record['key'], $record['pendingRequest'], $e);

                continue;
            }

            if ($this->isPendingRequest($record['key'], $record['pendingRequest'])) {
                $payloads[] = $record['payload'];
            }
        }

        if (!$payloads) {
            return $futures;
        }

        try {
            $this->writer->write($payloads);
        } catch (\Throwable $e) {
            foreach ($createdRequests as [$key, $pendingRequest]) {
                if (null !== $key) {
                    $this->detachPendingRequest($key, $pendingRequest);
                }
                $pendingRequest->abandon($e);
            }

            throw $e;
        }

        return $futures;
    }

    /** @internal */
    public function respond(int|float|string|null $id, mixed $result): void
    {
        $this->writer->write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    /** @internal */
    public function respondError(int|float|string|null $id, int $code, string $message, mixed $data = null): void
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if (null !== $data) {
            $error['data'] = $data;
        }

        $this->writer->write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ]);
    }

    /**
     * @param array<array-key, mixed>|object|null $params
     */
    public function notify(string $method, array|object|null $params = null): void
    {
        if ($this->shutdownStarted) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $params = JsonRpcValues::normalizeParams($params);
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if (null !== $params) {
            $payload['params'] = $params;
        }

        $this->writer->write($payload);
    }

    /**
     * @param list<mixed> $entries
     */
    private function handleBatch(array $entries): void
    {
        if (\count($entries) > $this->maximumBatchEntries) {
            throw new UnexpectedValueException('The JSON-RPC batch exceeds the configured entry limit.');
        }
        if (!$entries) {
            $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');

            return;
        }

        if ($this->isResponseBatch($entries)) {
            foreach ($entries as $response) {
                if (!$response instanceof \stdClass) {
                    continue;
                }

                /** @var array<string, mixed> $data */
                $data = get_object_vars($response);
                $this->handleResponse($data);
            }

            return;
        }

        $sender = new BatchResponseSender($this->writer);
        foreach ($entries as $entry) {
            if (!$entry instanceof \stdClass) {
                $sender->addInvalidRequest();
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = get_object_vars($entry);
            $this->handleRequest($data, $sender);
        }
        $sender->seal();
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function handleRequest(array $entry, ResponseSenderInterface $sender): void
    {
        try {
            $message = JsonRpcMessage::fromArray($entry);
        } catch (InvalidArgumentException) {
            if ($sender instanceof BatchResponseSender) {
                $sender->addInvalidRequest($this->validResponseId($entry['id'] ?? null));
            } else {
                $sender->respondError($this->validResponseId($entry['id'] ?? null), JsonRpcError::INVALID_REQUEST, 'Invalid Request');
            }

            return;
        }

        $responder = null;
        if (!$message->isNotification()) {
            if ($sender instanceof BatchResponseSender) {
                $sender->reserveResponse();
            }
            $responder = new RequestResponder($sender, $message->getId());
        }

        if (null === $this->messageHandler) {
            if (!$message->isNotification()) {
                $responder->reject(JsonRpcError::METHOD_NOT_FOUND, 'Method not found');
            }

            return;
        }

        if ($message->isNotification()) {
            try {
                ($this->messageHandler)($message, null);
            } catch (\Throwable) {
            }

            return;
        }

        if (\count($this->inboundRequests) >= $this->maximumConcurrentInboundRequests) {
            $responder->reject(JsonRpcError::SERVER_OVERLOADED, 'Too many concurrent requests.');

            return;
        }

        $result = ($this->messageHandler)($message, $responder);
        if ($result instanceof Future) {
            $key = $this->nextInboundRequestId++;
            $this->inboundRequests[$key] = $result;
            $result->catch(function (\Throwable $e): void {
                $this->inboundRequestError ??= $e;

                try {
                    $this->close();
                } catch (\Throwable) {
                }
            })->ignore();
            $result->finally(function () use ($key): void {
                unset($this->inboundRequests[$key]);
            })->ignore();
        }
    }

    /**
     * @param list<mixed> $entries
     */
    private function isResponseBatch(array $entries): bool
    {
        $hasResponse = false;
        foreach ($entries as $entry) {
            if (!$entry instanceof \stdClass) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = get_object_vars($entry);
            try {
                JsonRpcMessage::fromArray($data);

                return false;
            } catch (InvalidArgumentException) {
                $hasResponse = $hasResponse || $this->isResponse($data);
            }
        }

        return $hasResponse;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isResponse(array $data): bool
    {
        if (\array_key_exists('method', $data)) {
            return false;
        }

        if (\array_key_exists('result', $data) || \array_key_exists('error', $data)) {
            return true;
        }

        $id = $data['id'] ?? null;
        if (!JsonRpcValues::isValidId($id) || null === $id) {
            return false;
        }
        /** @var int|float|string $id */

        return isset($this->pendingRequests[$this->requestKey($id)]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleResponse(array $data): void
    {
        if (!\array_key_exists('id', $data)) {
            return;
        }

        $id = $data['id'];
        if (!JsonRpcValues::isValidId($id) || null === $id) {
            return;
        }
        /** @var int|float|string $id */

        $key = $this->requestKey($id);
        $pendingRequest = $this->pendingRequests[$key] ?? null;
        if (null === $pendingRequest) {
            return;
        }

        $hasResult = \array_key_exists('result', $data);
        $hasError = \array_key_exists('error', $data);
        if ('2.0' !== ($data['jsonrpc'] ?? null) || $hasResult === $hasError) {
            $this->failInvalidResponse($key, $pendingRequest);

            return;
        }

        if ($hasResult) {
            if (JsonRpcValues::containsNonFiniteFloat($data['result'])) {
                $this->failInvalidResponse($key, $pendingRequest);

                return;
            }

            if ($this->detachPendingRequest($key, $pendingRequest)) {
                $pendingRequest->complete($data['result']);
            }

            return;
        }

        $error = $data['error'];
        if (!$error instanceof \stdClass) {
            $this->failInvalidResponse($key, $pendingRequest);

            return;
        }

        $error = get_object_vars($error);
        if (!\is_int($error['code'] ?? null) || !\is_string($error['message'] ?? null) || JsonRpcValues::containsNonFiniteFloat($error['data'] ?? null)) {
            $this->failInvalidResponse($key, $pendingRequest);

            return;
        }

        if ($this->detachPendingRequest($key, $pendingRequest)) {
            $pendingRequest->error(new JsonRpcException($error['code'], $error['message'], $error['data'] ?? null));
        }
    }

    private function registerPendingRequest(string $key, ?Cancellation $cancellation): PendingRequest
    {
        $pendingRequest = new PendingRequest($cancellation);
        $this->pendingRequests[$key] = $pendingRequest;
        $pendingRequest->subscribeToCancellation(function (CancelledException $error) use ($key, $pendingRequest): void {
            $this->failPendingRequest($key, $pendingRequest, $error);
        });

        return $pendingRequest;
    }

    private function isPendingRequest(string $key, PendingRequest $pendingRequest): bool
    {
        return ($this->pendingRequests[$key] ?? null) === $pendingRequest;
    }

    private function detachPendingRequest(string $key, PendingRequest $pendingRequest): bool
    {
        if (!$this->isPendingRequest($key, $pendingRequest)) {
            return false;
        }

        unset($this->pendingRequests[$key]);

        return true;
    }

    private function failPendingRequest(string $key, PendingRequest $pendingRequest, \Throwable $error): bool
    {
        if (!$this->detachPendingRequest($key, $pendingRequest)) {
            return false;
        }

        $pendingRequest->error($error);

        return true;
    }

    private function failInvalidResponse(string $key, PendingRequest $pendingRequest): void
    {
        $this->failPendingRequest($key, $pendingRequest, new InvalidResponseException('Received an invalid JSON-RPC response.'));
    }

    private function requestKey(int|float|string $id): string
    {
        return JsonRpcValues::requestKey($id);
    }

    private function validResponseId(mixed $id): int|float|string|null
    {
        if (!JsonRpcValues::isValidId($id)) {
            return null;
        }
        /** @var int|float|string|null $id */

        return $id;
    }
}
