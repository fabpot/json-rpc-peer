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

final class BatchRequest
{
    /**
     * @param array<array-key, mixed>|object|null $params
     */
    public function __construct(
        private readonly string $method,
        private readonly array|object|null $params = null,
        private readonly ?Cancellation $cancellation = null,
    ) {}

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return array<array-key, mixed>|object|null
     */
    public function getParams(): array|object|null
    {
        return $this->params;
    }

    /** @internal */
    public function getCancellation(): ?Cancellation
    {
        return $this->cancellation;
    }
}
