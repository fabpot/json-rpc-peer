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

final class BatchNotification
{
    /**
     * @param array<array-key, mixed> $params
     */
    public function __construct(
        private readonly string $method,
        private readonly array $params = [],
    ) {}

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
