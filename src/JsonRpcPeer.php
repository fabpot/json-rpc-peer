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

    /** @var array<string, DeferredFuture<mixed>> */
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
    ) {
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
                $error = $e;
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
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose)->ignore();
    }

    private function listenLoop(): void
    {
        while (true) {
            try {
                $message = $this->transport->receive($this->connectionCancellation->getCancellation());
            } catch (CancelledException $e) {
                if (!$this->shutdownStarted) {
                    throw $e;
                }

                return;
            }
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
        foreach ($this->pendingRequests as $deferred) {
            if (!$deferred->isComplete()) {
                $deferred->error(new ConnectionClosedException('The JSON-RPC connection closed before a response was received.'));
            }
        }
        $this->pendingRequests = [];
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
            $decoded = json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->respondError(null, JsonRpcError::PARSE_ERROR, 'Parse error');

            return;
        }

        if (!\is_array($decoded)) {
            $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');

            return;
        }

        if ('[' === $message[0]) {
            if (!array_is_list($decoded)) {
                $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');

                return;
            }

            $this->handleBatch($decoded);

            return;
        }
        /** @var array<string, mixed> $decoded */

        if ($this->isResponse($decoded)) {
            $this->handleResponse($decoded);
        } else {
            $this->handleRequest($decoded, $this);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return Future<mixed>
     */
    public function request(string $method, array $params = []): Future
    {
        return $this->startRequest($method, $params)->getFuture();
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function startRequest(string $method, array $params = []): OutboundRequest
    {
        if ($this->shutdownStarted) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $id = $this->nextRequestId++;
        $deferred = new DeferredFuture();
        $this->pendingRequests[$this->requestKey($id)] = $deferred;

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];
        if ($params) {
            $payload['params'] = $params;
        }

        try {
            $this->writer->write($payload);
        } catch (\Throwable $e) {
            unset($this->pendingRequests[$this->requestKey($id)]);
            throw $e;
        }

        return new OutboundRequest($id, $deferred->getFuture());
    }

    /**
     * @return list<Future<mixed>>
     */
    public function batch(BatchRequest|BatchNotification ...$entries): array
    {
        if (!$entries) {
            throw new InvalidArgumentException('A JSON-RPC batch must contain at least one entry.');
        }
        if ($this->shutdownStarted) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $payloads = [];
        $requestKeys = [];
        $futures = [];
        foreach ($entries as $entry) {
            $payload = [
                'jsonrpc' => '2.0',
                'method' => $entry->getMethod(),
            ];
            if ($entry->getParams()) {
                $payload['params'] = $entry->getParams();
            }

            if ($entry instanceof BatchRequest) {
                $id = $this->nextRequestId++;
                $deferred = new DeferredFuture();
                $key = $this->requestKey($id);
                $this->pendingRequests[$key] = $deferred;
                $payload['id'] = $id;
                $requestKeys[] = $key;
                $futures[] = $deferred->getFuture();
            }

            $payloads[] = $payload;
        }

        try {
            $this->writer->write($payloads);
        } catch (\Throwable $e) {
            foreach ($requestKeys as $key) {
                unset($this->pendingRequests[$key]);
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
     * @param array<array-key, mixed> $params
     */
    public function notify(string $method, array $params = []): void
    {
        if ($this->shutdownStarted) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if ($params) {
            $payload['params'] = $params;
        }

        $this->writer->write($payload);
    }

    /**
     * @param list<mixed> $entries
     */
    private function handleBatch(array $entries): void
    {
        if (!$entries) {
            $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');

            return;
        }

        if ($this->isResponseBatch($entries)) {
            foreach ($entries as $response) {
                if (!\is_array($response) || array_is_list($response)) {
                    continue;
                }
                /** @var array<string, mixed> $response */

                $this->handleResponse($response);
            }

            return;
        }

        $sender = new BatchResponseSender($this->writer);
        foreach ($entries as $entry) {
            if (!\is_array($entry) || array_is_list($entry)) {
                $sender->addInvalidRequest();
                continue;
            }
            /** @var array<string, mixed> $entry */

            $this->handleRequest($entry, $sender);
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
                $sender->addInvalidRequest();
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
                $responder->reject(JsonRpcError::METHOD_NOT_FOUND, \sprintf('Method not found: %s', $message->getMethod()));
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
            if (!\is_array($entry) || array_is_list($entry)) {
                continue;
            }
            /** @var array<string, mixed> $entry */

            try {
                JsonRpcMessage::fromArray($entry);

                return false;
            } catch (InvalidArgumentException) {
                $hasResponse = $hasResponse || $this->isResponse($entry);
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
        $deferred = $this->pendingRequests[$key] ?? null;
        if (null === $deferred) {
            return;
        }

        $hasResult = \array_key_exists('result', $data);
        $hasError = \array_key_exists('error', $data);
        if ('2.0' !== ($data['jsonrpc'] ?? null) || $hasResult === $hasError) {
            $this->failInvalidResponse($key, $deferred);

            return;
        }

        if ($hasResult) {
            if (JsonRpcValues::containsNonFiniteFloat($data['result'])) {
                $this->failInvalidResponse($key, $deferred);

                return;
            }

            unset($this->pendingRequests[$key]);
            $deferred->complete($data['result']);

            return;
        }

        $error = $data['error'];
        if (!\is_array($error) || array_is_list($error) || !\is_int($error['code'] ?? null) || !\is_string($error['message'] ?? null) || JsonRpcValues::containsNonFiniteFloat($error['data'] ?? null)) {
            $this->failInvalidResponse($key, $deferred);

            return;
        }

        unset($this->pendingRequests[$key]);
        $deferred->error(new JsonRpcException($error['code'], $error['message'], $error['data'] ?? null));
    }

    /**
     * @param DeferredFuture<mixed> $deferred
     */
    private function failInvalidResponse(string $key, DeferredFuture $deferred): void
    {
        unset($this->pendingRequests[$key]);
        $deferred->error(new InvalidResponseException('Received an invalid JSON-RPC response.'));
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
