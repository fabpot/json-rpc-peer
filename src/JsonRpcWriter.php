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

use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\PayloadEncodingException;

/** @internal */
final class JsonRpcWriter
{
    private bool $closed = false;

    public function __construct(
        private readonly JsonRpcTransportInterface $transport,
        private readonly ?TrafficLoggerInterface $trafficLogger,
    ) {}

    /**
     * @param array<array-key, mixed> $payload
     */
    public function write(array $payload): void
    {
        if ($this->closed) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        $line = $this->encode($payload);
        $this->trafficLogger?->logOutbound($line);

        $this->transport->send($line);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public function encode(array $payload): string
    {
        if ($this->closed) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.');
        }

        try {
            return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new PayloadEncodingException('The JSON-RPC payload cannot be encoded to JSON.', 0, $e);
        }
    }
}
