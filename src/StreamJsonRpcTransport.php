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
use Fabpot\JsonRpc\Exception\RuntimeException;

final class StreamJsonRpcTransport implements JsonRpcTransportInterface
{
    private string $buffer = '';
    private bool $ended = false;

    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
    ) {}

    public function receive(?Cancellation $cancellation = null): ?string
    {
        while (false === $position = strpos($this->buffer, "\n")) {
            if ($this->ended) {
                if ('' === $this->buffer) {
                    return null;
                }

                $message = rtrim($this->buffer, "\r");
                $this->buffer = '';

                return $message;
            }

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

        $message = rtrim(substr($this->buffer, 0, $position), "\r");
        $this->buffer = substr($this->buffer, $position + 1);

        return $message;
    }

    public function send(string $message): void
    {
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
}
