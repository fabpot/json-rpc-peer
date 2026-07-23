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
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\InvalidResponseException;
use Fabpot\JsonRpc\Exception\JsonRpcException;

use function Amp\async;

/**
 * Minimal bidirectional JSON-RPC 2.0 peer.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcPeer implements ResponseSenderInterface
{
    /** @var (callable(JsonRpcMessage, RequestResponder|null): mixed)|null */
    private $messageHandler;

    private readonly JsonRpcWriter $writer;
    private readonly DeferredCancellation $connectionCancellation;
    private int $nextRequestId = 1;
    private bool $listenerStopped = false;
    private bool $transportClosed = false;

    /** @var array<string, DeferredFuture<mixed>> */
    private array $pendingRequests = [];

    /** @var array<string, Future<mixed>> */
    private array $inboundRequests = [];

    public function __construct(
        private readonly JsonRpcTransportInterface $transport,
        private readonly ?TrafficLoggerInterface $trafficLogger = null,
    ) {
        $this->writer = new JsonRpcWriter($transport, $trafficLogger);
        $this->connectionCancellation = new DeferredCancellation();
    }

    public function getConnectionCancellation(): Cancellation
    {
        return $this->connectionCancellation->getCancellation();
    }

    /**
     * @param callable(JsonRpcMessage, RequestResponder|null): mixed $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function listen(): void
    {
        async($this->listenLoop(...))->await();

        while ($this->inboundRequests) {
            foreach ($this->inboundRequests as $future) {
                $future->await();
            }
        }
    }

    public function close(): void
    {
        if (!$this->transportClosed) {
            $this->transportClosed = true;
            $this->transport->close();
        }

        $this->stop();
    }

    private function listenLoop(): void
    {
        try {
            while (null !== $message = $this->transport->receive()) {
                $message = trim($message, " \t\r\n");
                if ('' === $message) {
                    continue;
                }

                try {
                    $this->processMessage($message);
                } catch (ConnectionClosedException) {
                    // the response is undeliverable, keep draining inbound messages
                }
            }
        } finally {
            $this->stop();
        }
    }

    private function stop(): void
    {
        if ($this->listenerStopped) {
            return;
        }

        $this->listenerStopped = true;
        $this->connectionCancellation->cancel();
        foreach ($this->pendingRequests as $deferred) {
            if (!$deferred->isComplete()) {
                $deferred->error(new ConnectionClosedException('The JSON-RPC connection closed before a response was received.'));
            }
        }
        $this->pendingRequests = [];
    }

    private function processMessage(string $message): void
    {
        $this->trafficLogger?->logInbound($message);

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
        if ($this->listenerStopped) {
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

        return $deferred->getFuture();
    }

    /**
     * @return list<Future<mixed>>
     */
    public function batch(BatchRequest|BatchNotification ...$entries): array
    {
        if (!$entries) {
            throw new InvalidArgumentException('A JSON-RPC batch must contain at least one entry.');
        }
        if ($this->listenerStopped && array_any($entries, static fn(BatchRequest|BatchNotification $entry): bool => $entry instanceof BatchRequest)) {
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

    public function respond(int|float|string|null $id, mixed $result): void
    {
        $this->writer->write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

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
            $key = spl_object_hash($result);
            $this->inboundRequests[$key] = $result;
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

        return (\is_int($id) || \is_float($id) || \is_string($id)) && isset($this->pendingRequests[$this->requestKey($id)]);
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
        if (!\is_int($id) && !\is_string($id) && (!\is_float($id) || !JsonRpcValues::isSafeFloatId($id))) {
            return;
        }

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
        if (\is_int($id) || \is_string($id)) {
            return $id;
        }

        if (!\is_float($id) || !JsonRpcValues::isSafeFloatId($id)) {
            return null;
        }

        return $id;
    }
}
