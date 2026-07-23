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

interface JsonRpcTransportInterface
{
    public function receive(?Cancellation $cancellation = null): ?string;

    public function send(string $message): void;

    public function close(): void;
}
