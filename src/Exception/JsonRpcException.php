<?php

/*
 * This file is part of the fabpot/json-rpc-peer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fabpot\JsonRpc\Exception;

/**
 * Represents a JSON-RPC error returned by a request handler or remote peer.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcException extends RuntimeException
{
    public function __construct(
        int $code,
        string $message,
        private readonly mixed $data = null,
    ) {
        parent::__construct($message, $code);
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
