<?php

/*
 * This file is part of the fabpot/json-rpc-peer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fabpot\JsonRpc\Tests;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\WritableStream;

final class CapturingStream implements WritableStream
{
    private string $contents = '';
    private bool $closed = false;

    public function write(string $bytes): void
    {
        if ($this->closed) {
            throw new ClosedException('The stream is closed.');
        }

        $this->contents .= $bytes;
    }

    public function end(): void
    {
        $this->close();
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function messages(): array
    {
        $messages = [];
        foreach (explode("\n", $this->contents) as $line) {
            if ('' === $line) {
                continue;
            }

            $message = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($message)) {
                throw new \UnexpectedValueException('Expected a JSON object or array.');
            }
            $messages[] = $message;
        }

        return $messages;
    }
}
