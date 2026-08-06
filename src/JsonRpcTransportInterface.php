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

/**
 * Exchanges complete JSON-RPC messages over an owned connection.
 *
 * A transport passed to JsonRpcPeer is owned by the peer and closed when the peer closes or its listener stops.
 */
interface JsonRpcTransportInterface
{
    /**
     * This method is called by a single listener and must honor cancellation.
     */
    public function receive(?Cancellation $cancellation = null): ?string;

    /**
     * This method may be called concurrently; implementations must serialize writes.
     */
    public function send(string $message): void;

    /**
     * This method must be idempotent and unblock any pending receive() call.
     */
    public function close(): void;
}
