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

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;

/**
 * Yields its initial contents once, then fails every subsequent read.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class FailingReadStream implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private bool $consumed = false;

    public function __construct(
        private readonly string $contents,
    ) {}

    public function read(?Cancellation $cancellation = null): string
    {
        if (!$this->consumed) {
            $this->consumed = true;

            return $this->contents;
        }

        throw new StreamException('The read failed.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
