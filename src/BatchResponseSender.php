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

final class BatchResponseSender implements ResponseSenderInterface
{
    /** @var list<array<string, mixed>> */
    private array $responses = [];
    private int $pendingResponses = 0;
    private bool $sealed = false;
    private bool $sent = false;

    public function __construct(
        private readonly JsonRpcWriter $writer,
    ) {}

    public function reserveResponse(): void
    {
        ++$this->pendingResponses;
    }

    public function addInvalidRequest(): void
    {
        $this->responses[] = [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => JsonRpcError::INVALID_REQUEST,
                'message' => 'Invalid Request',
            ],
        ];
    }

    public function respond(int|float|string|null $id, mixed $result): void
    {
        $this->settle([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    public function respondError(int|float|string|null $id, int $code, string $message, mixed $data = null): void
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if (null !== $data) {
            $error['data'] = $data;
        }

        $this->settle([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ]);
    }

    public function seal(): void
    {
        $this->sealed = true;
        $this->flush();
    }

    /**
     * @param array<string, mixed> $response
     */
    private function settle(array $response): void
    {
        $this->responses[] = $response;
        --$this->pendingResponses;
        $this->flush();
    }

    private function flush(): void
    {
        if (!$this->sealed || $this->sent || 0 !== $this->pendingResponses || !$this->responses) {
            return;
        }

        $this->sent = true;
        $this->writer->write($this->responses);
    }
}
