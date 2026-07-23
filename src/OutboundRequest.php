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

use Amp\Future;

final class OutboundRequest
{
    /**
     * @param Future<mixed> $future
     */
    public function __construct(
        private readonly int $id,
        private readonly Future $future,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return Future<mixed>
     */
    public function getFuture(): Future
    {
        return $this->future;
    }
}
