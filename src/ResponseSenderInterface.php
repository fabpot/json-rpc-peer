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

interface ResponseSenderInterface
{
    public function respond(int|float|string|null $id, mixed $result): void;

    public function respondError(int|float|string|null $id, int $code, string $message, mixed $data = null): void;
}
