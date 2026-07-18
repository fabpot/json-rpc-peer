<?php

/*
 * This file is part of the Symfony\Component package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Agent\Acp\JsonRpc;

/**
 * Resolves a single inbound request, now or later.
 *
 * Synchronous handlers resolve inline; a long-running handler hands the
 * responder to its coroutine and resolves it once the work ends, while the
 * dispatcher keeps reading inbound messages. A responder resolves at most once.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class RequestResponder
{
    private bool $settled = false;

    public function __construct(
        private readonly JsonRpcPeer $peer,
        private readonly int|string $id,
    ) {
    }

    public function resolve(mixed $result): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->peer->respond($this->id, $result);
    }

    public function reject(int $code, string $message, mixed $data = null): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->peer->respondError($this->id, $code, $message, $data);
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }
}
