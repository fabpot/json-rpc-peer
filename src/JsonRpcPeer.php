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

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\DeferredFuture;
use Amp\Future;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\InvalidResponseException;
use Fabpot\JsonRpc\Exception\JsonRpcException;

use function Amp\ByteStream\splitLines;

/**
 * Minimal bidirectional JSON-RPC 2.0 peer over line-delimited JSON streams.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcPeer implements ResponseSenderInterface
{
    /** @var (callable(JsonRpcMessage, RequestResponder|null): void)|null */
    private $messageHandler;

    private readonly JsonRpcWriter $writer;
    private int $nextRequestId = 1;

    /** @var array<string, DeferredFuture<mixed>> */
    private array $pendingRequests = [];

    public function __construct(
        private readonly ReadableStream $input,
        WritableStream $output,
        private readonly ?TrafficLoggerInterface $trafficLogger = null,
    ) {
        $this->writer = new JsonRpcWriter($output, $trafficLogger);
    }

    /**
     * @param callable(JsonRpcMessage, RequestResponder|null): void $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function listen(): void
    {
        try {
            foreach (splitLines($this->input) as $line) {
                $line = trim($line, " \t\r\n");
                if ('' === $line) {
                    continue;
                }

                $this->trafficLogger?->logInbound($line);

                try {
                    $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->respondError(null, JsonRpcError::PARSE_ERROR, 'Parse error');
                    continue;
                }

                if (!\is_array($decoded)) {
                    $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');
                    continue;
                }

                if (array_is_list($decoded)) {
                    $this->handleBatch($decoded);
                    continue;
                }
                /** @var array<string, mixed> $decoded */

                $this->handleEntry($decoded, $this);
            }
        } finally {
            foreach ($this->pendingRequests as $deferred) {
                if (!$deferred->isComplete()) {
                    $deferred->error(new ConnectionClosedException('The JSON-RPC connection closed before a response was received.'));
                }
            }
            $this->pendingRequests = [];
        }
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return Future<mixed>
     */
    public function request(string $method, array $params = []): Future
    {
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
            $this->send($payload);
        } catch (\Throwable $e) {
            unset($this->pendingRequests[$this->requestKey($id)]);
            throw $e;
        }

        return $deferred->getFuture();
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

        $this->send($payload);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function send(array $payload): void
    {
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

        $sender = new BatchResponseSender($this->writer);
        foreach ($entries as $entry) {
            if (!\is_array($entry) || array_is_list($entry)) {
                $sender->addInvalidRequest(null);
                continue;
            }
            /** @var array<string, mixed> $entry */

            $this->handleEntry($entry, $sender);
        }
        $sender->seal();
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function handleEntry(array $entry, ResponseSenderInterface $sender): void
    {
        if ($this->isResponse($entry)) {
            $this->handleResponse($entry);

            return;
        }

        try {
            $message = JsonRpcMessage::fromArray($entry);
        } catch (InvalidArgumentException) {
            if ($sender instanceof BatchResponseSender) {
                $sender->addInvalidRequest(null);
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

        if (null !== $this->messageHandler) {
            ($this->messageHandler)($message, $responder);
        }
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
        if (!\is_int($id) && !\is_string($id) && (!\is_float($id) || !is_finite($id))) {
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
            unset($this->pendingRequests[$key]);
            $deferred->complete($data['result']);

            return;
        }

        $error = $data['error'];
        if (!\is_array($error) || array_is_list($error) || !\is_int($error['code'] ?? null) || !\is_string($error['message'] ?? null)) {
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
        if (\is_float($id) && $id === floor($id) && $id >= \PHP_INT_MIN && $id <= \PHP_INT_MAX) {
            $id = (int) $id;
        }

        return get_debug_type($id) . ':' . $id;
    }

    private function validResponseId(mixed $id): int|float|string|null
    {
        return \is_int($id) || \is_string($id) || (\is_float($id) && is_finite($id)) ? $id : null;
    }
}
