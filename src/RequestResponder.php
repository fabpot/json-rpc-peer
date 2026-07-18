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
 * Resolves a single inbound request, now or later.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class RequestResponder
{
    private bool $settled = false;

    public function __construct(
        private readonly JsonRpcPeer $peer,
        private readonly int|float|string|null $id,
    ) {}

    public function resolve(mixed $result): void
    {
        if ($this->settled) {
            return;
        }

        $this->peer->respond($this->id, $result);
        $this->settled = true;
    }

    public function reject(int $code, string $message, mixed $data = null): void
    {
        if ($this->settled) {
            return;
        }

        $this->peer->respondError($this->id, $code, $message, $data);
        $this->settled = true;
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }
}
