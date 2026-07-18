<?php

/*
 * This file is part of the Symfony\Component package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Agent\Acp\JsonRpc;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;

use function Amp\ByteStream\splitLines;

/**
 * Minimal bidirectional JSON-RPC 2.0 peer over line-delimited JSON streams.
 *
 * One JSON-RPC message per line. The peer reads inbound messages from a
 * readable stream and writes responses and notifications to a writable stream.
 * It carries no protocol semantics of its own, so it can be tested against
 * in-memory byte streams.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcPeer
{
    /** @var (callable(JsonRpcMessage): void)|null */
    private $messageHandler;

    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
        private readonly ?TrafficLoggerInterface $trafficLogger = null,
    ) {
    }

    /**
     * Register the handler invoked for every inbound, well-formed message.
     *
     * @param callable(JsonRpcMessage): void $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    /**
     * Read inbound lines until the stream closes, dispatching each message.
     *
     * Malformed lines are answered with a JSON-RPC parse error and otherwise
     * skipped so a single bad line never tears down the connection.
     */
    public function listen(): void
    {
        foreach (splitLines($this->input) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $this->trafficLogger?->logInbound($line);

            try {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->sendRaw(JsonRpcResponse::error(null, JsonRpcError::PARSE_ERROR, 'Parse error'));
                continue;
            }

            if (!\is_array($decoded)) {
                $this->sendRaw(JsonRpcResponse::error(null, JsonRpcError::INVALID_REQUEST, 'Invalid Request'));
                continue;
            }

            $message = JsonRpcMessage::fromArray($decoded);
            if (null !== $this->messageHandler) {
                ($this->messageHandler)($message);
            }
        }
    }

    /**
     * Send a response to a request identified by $id.
     */
    public function respond(int|string $id, mixed $result): void
    {
        $this->sendRaw(JsonRpcResponse::success($id, $result));
    }

    /**
     * Send an error response for a request identified by $id.
     */
    public function respondError(int|string|null $id, int $code, string $message, mixed $data = null): void
    {
        $this->sendRaw(JsonRpcResponse::error($id, $code, $message, $data));
    }

    /**
     * Send a notification (a request with no id, expecting no response).
     *
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params): void
    {
        $this->sendRaw([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendRaw(array $payload): void
    {
        $line = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $this->trafficLogger?->logOutbound($line);
        $this->output->write($line."\n");
    }
}
