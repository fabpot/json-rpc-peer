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

                if ($this->isResponse($decoded)) {
                    $this->handleResponse($decoded);
                } else {
                    $this->handleRequest($decoded, $this);
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

        ($this->messageHandler)($message, $responder);
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
