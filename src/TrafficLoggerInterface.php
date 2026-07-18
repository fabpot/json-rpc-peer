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

/**
 * Records raw JSON-RPC traffic for post-hoc debugging.
 *
 * Implementations must redact secrets before persisting a line.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
interface TrafficLoggerInterface
{
    public function logInbound(string $line): void;

    public function logOutbound(string $line): void;
}
