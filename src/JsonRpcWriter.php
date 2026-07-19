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

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\RuntimeException;

final class JsonRpcWriter
{
    public function __construct(
        private readonly WritableStream $output,
        private readonly ?TrafficLoggerInterface $trafficLogger,
    ) {}

    /**
     * @param array<array-key, mixed> $payload
     */
    public function write(array $payload): void
    {
        try {
            $line = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('The JSON-RPC payload cannot be encoded to JSON.', 0, $e);
        }

        $this->trafficLogger?->logOutbound($line);

        try {
            $this->output->write($line . "\n");
        } catch (ClosedException $e) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.', 0, $e);
        } catch (StreamException $e) {
            throw new RuntimeException('Failed to write to the JSON-RPC connection.', 0, $e);
        }
    }
}
