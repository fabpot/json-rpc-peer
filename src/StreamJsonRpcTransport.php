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

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\RuntimeException;
use Fabpot\JsonRpc\Exception\UnexpectedValueException;

final class StreamJsonRpcTransport implements JsonRpcTransportInterface
{
    private string $buffer = '';
    private bool $ended = false;

    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
        private readonly int $maximumMessageBytes = 16777216,
    ) {
        if ($maximumMessageBytes < 1) {
            throw new InvalidArgumentException('The line-delimited transport message limit must be a positive integer.');
        }
    }

    public function receive(?Cancellation $cancellation = null): ?string
    {
        while (false === $position = strpos($this->buffer, "\n")) {
            if ($this->ended) {
                if ('' === $this->buffer) {
                    return null;
                }

                $message = $this->removeCarriageReturn($this->buffer);
                $this->buffer = '';
                $this->assertMessageSize($message);

                return $message;
            }

            $this->assertPendingMessageSize();

            try {
                $chunk = $this->input->read($cancellation);
            } catch (ClosedException) {
                $chunk = null;
            } catch (StreamException $e) {
                throw new RuntimeException('Failed to read from the JSON-RPC connection.', 0, $e);
            }

            if (null === $chunk) {
                $this->ended = true;
            } else {
                $this->buffer .= $chunk;
            }
        }

        $message = $this->removeCarriageReturn(substr($this->buffer, 0, $position));
        $this->buffer = substr($this->buffer, $position + 1);
        $this->assertMessageSize($message);

        return $message;
    }

    public function send(string $message): void
    {
        if (\strlen($message) > $this->maximumMessageBytes) {
            throw new InvalidArgumentException('The JSON-RPC message exceeds the configured limit.');
        }

        try {
            $this->output->write($message . "\n");
        } catch (ClosedException $e) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.', 0, $e);
        } catch (StreamException $e) {
            throw new RuntimeException('Failed to write to the JSON-RPC connection.', 0, $e);
        }
    }

    public function close(): void
    {
        $this->input->close();
        if ($this->output !== $this->input) {
            $this->output->close();
        }
    }

    private function assertPendingMessageSize(): void
    {
        $length = \strlen($this->buffer);
        if ($length <= $this->maximumMessageBytes
            || ($length - 1 === $this->maximumMessageBytes && str_ends_with($this->buffer, "\r"))
        ) {
            return;
        }

        throw new UnexpectedValueException('The JSON-RPC message exceeds the configured limit.');
    }

    private function assertMessageSize(string $message): void
    {
        if (\strlen($message) > $this->maximumMessageBytes) {
            throw new UnexpectedValueException('The JSON-RPC message exceeds the configured limit.');
        }
    }

    private function removeCarriageReturn(string $message): string
    {
        return str_ends_with($message, "\r") ? substr($message, 0, -1) : $message;
    }
}
