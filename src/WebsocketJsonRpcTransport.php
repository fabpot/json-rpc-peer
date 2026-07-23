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

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\RuntimeException;
use Fabpot\JsonRpc\Exception\UnexpectedValueException;

final class WebsocketJsonRpcTransport implements JsonRpcTransportInterface
{
    public function __construct(
        private readonly WebsocketClient $client,
    ) {}

    public function receive(?Cancellation $cancellation = null): ?string
    {
        try {
            $message = $this->client->receive($cancellation);
            if (null === $message) {
                return null;
            }
            if ($message->isBinary()) {
                $message->close();

                throw new UnexpectedValueException('Binary WebSocket messages cannot contain JSON-RPC payloads.');
            }

            return $message->buffer($cancellation);
        } catch (WebsocketClosedException) {
            return null;
        } catch (StreamException $e) {
            throw new RuntimeException('Failed to read from the JSON-RPC connection.', 0, $e);
        }
    }

    public function send(string $message): void
    {
        try {
            $this->client->sendText($message);
        } catch (WebsocketClosedException $e) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.', 0, $e);
        }
    }

    public function close(): void
    {
        $this->client->close();
    }
}
