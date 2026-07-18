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
final class JsonRpcPeer
{
    /** @var (callable(JsonRpcMessage): void)|null */
    private $messageHandler;

    private int $nextRequestId = 1;

    /** @var array<string, DeferredFuture<mixed>> */
    private array $pendingRequests = [];

    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
        private readonly ?TrafficLoggerInterface $trafficLogger = null,
    ) {}

    /**
     * @param callable(JsonRpcMessage): void $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function listen(): void
    {
        try {
            foreach (splitLines($this->input) as $line) {
                $line = trim($line);
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

                if (!\is_array($decoded) || array_is_list($decoded)) {
                    $this->respondError(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request');
                    continue;
                }
                /** @var array<string, mixed> $decoded */

                if ($this->isResponse($decoded)) {
                    $this->handleResponse($decoded);
                    continue;
                }

                try {
                    $message = JsonRpcMessage::fromArray($decoded);
                } catch (InvalidArgumentException) {
                    $id = $this->validResponseId($decoded['id'] ?? null);
                    $this->respondError($id, JsonRpcError::INVALID_REQUEST, 'Invalid Request');
                    continue;
                }

                if (null !== $this->messageHandler) {
                    ($this->messageHandler)($message);
                }
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

        try {
            $this->send([
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => $method,
                'params' => $params,
            ]);
        } catch (\Throwable $e) {
            unset($this->pendingRequests[$this->requestKey($id)]);
            throw $e;
        }

        return $deferred->getFuture();
    }

    public function respond(int|float|string|null $id, mixed $result): void
    {
        $this->send([
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

        $this->send([
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
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload): void
    {
        $line = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $this->trafficLogger?->logOutbound($line);
        $this->output->write($line . "\n");
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
        if (!\is_int($id) && !\is_float($id) && !\is_string($id)) {
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
        return get_debug_type($id) . ':' . $id;
    }

    private function validResponseId(mixed $id): int|float|string|null
    {
        return \is_int($id) || \is_float($id) || \is_string($id) ? $id : null;
    }
}
