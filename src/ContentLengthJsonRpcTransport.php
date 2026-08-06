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

final class ContentLengthJsonRpcTransport implements JsonRpcTransportInterface
{
    private string $buffer = '';
    private bool $ended = false;

    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
        private readonly int $maximumHeaderBytes = 8192,
        private readonly int $maximumMessageBytes = 16777216,
    ) {
        if ($maximumHeaderBytes < 1 || $maximumMessageBytes < 1) {
            throw new InvalidArgumentException('Content-length transport limits must be positive integers.');
        }
    }

    public function receive(?Cancellation $cancellation = null): ?string
    {
        $headerEnd = $this->findHeaderEnd();
        while (null === $headerEnd) {
            if ($this->ended) {
                if ('' === $this->buffer) {
                    return null;
                }

                throw new UnexpectedValueException('The JSON-RPC connection ended before the message headers were complete.');
            }

            if (\strlen($this->buffer) > $this->maximumHeaderBytes + 3) {
                throw new UnexpectedValueException('The JSON-RPC message headers exceed the configured limit.');
            }

            $this->read($cancellation);
            $headerEnd = $this->findHeaderEnd();
        }

        if ($headerEnd > $this->maximumHeaderBytes) {
            throw new UnexpectedValueException('The JSON-RPC message headers exceed the configured limit.');
        }

        $contentLength = $this->parseContentLength(substr($this->buffer, 0, $headerEnd));
        $bodyOffset = $headerEnd + 4;
        $frameLength = $bodyOffset + $contentLength;

        while (\strlen($this->buffer) < $frameLength) {
            if ($this->ended) {
                throw new UnexpectedValueException('The JSON-RPC connection ended before the message body was complete.');
            }

            $this->read($cancellation);
        }

        $message = substr($this->buffer, $bodyOffset, $contentLength);
        $this->buffer = substr($this->buffer, $frameLength);

        return $message;
    }

    public function send(string $message): void
    {
        $length = \strlen($message);
        if ($length > $this->maximumMessageBytes) {
            throw new InvalidArgumentException('The JSON-RPC message exceeds the configured limit.');
        }

        try {
            $this->output->write("Content-Length: {$length}\r\n\r\n{$message}");
        } catch (ClosedException $e) {
            throw new ConnectionClosedException('The JSON-RPC connection is closed.', 0, $e);
        } catch (StreamException $e) {
            throw new RuntimeException('Failed to write to the JSON-RPC connection.', 0, $e);
        }
    }

    public function close(): void
    {
        $error = null;
        try {
            $this->input->close();
        } catch (\Throwable $e) {
            $error = $e;
        }

        if ($this->output !== $this->input) {
            try {
                $this->output->close();
            } catch (\Throwable $e) {
                $error ??= $e;
            }
        }

        if (null !== $error) {
            throw $error;
        }
    }

    private function findHeaderEnd(): ?int
    {
        $position = strpos($this->buffer, "\r\n\r\n");

        return false === $position ? null : $position;
    }

    private function parseContentLength(string $headerBlock): int
    {
        $contentLength = null;
        foreach (explode("\r\n", $headerBlock) as $header) {
            if (!str_contains($header, ':')) {
                throw new UnexpectedValueException('A JSON-RPC message header is malformed.');
            }

            [$name, $value] = explode(':', $header, 2);
            if (!preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $name)) {
                throw new UnexpectedValueException('A JSON-RPC message header name is invalid.');
            }
            if (!preg_match('/^[\x09\x20-\x7E\x80-\xFF]*$/D', $value)) {
                throw new UnexpectedValueException('A JSON-RPC message header value is invalid.');
            }
            $value = trim($value, " \t");

            if (0 !== strcasecmp($name, 'Content-Length')) {
                continue;
            }

            if (null !== $contentLength) {
                throw new UnexpectedValueException('The JSON-RPC message contains duplicate Content-Length headers.');
            }

            if (!preg_match('/^[0-9]+$/D', $value)) {
                throw new UnexpectedValueException('The Content-Length header is invalid.');
            }

            $normalizedValue = ltrim($value, '0');
            if ('' === $normalizedValue) {
                $normalizedValue = '0';
            }
            $maximumMessageBytes = (string) $this->maximumMessageBytes;
            if (\strlen($normalizedValue) > \strlen($maximumMessageBytes)
                || (\strlen($normalizedValue) === \strlen($maximumMessageBytes) && strcmp($normalizedValue, $maximumMessageBytes) > 0)
            ) {
                throw new UnexpectedValueException('The JSON-RPC message exceeds the configured limit.');
            }

            $contentLength = (int) $normalizedValue;
        }

        if (null === $contentLength) {
            throw new UnexpectedValueException('The JSON-RPC message is missing a Content-Length header.');
        }

        return $contentLength;
    }

    private function read(?Cancellation $cancellation): void
    {
        try {
            $chunk = $this->input->read($cancellation);
        } catch (ClosedException) {
            $chunk = null;
        } catch (StreamException $e) {
            throw new RuntimeException('Failed to read from the JSON-RPC connection.', 0, $e);
        } catch (\Throwable $e) {
            if (!$this->input->isClosed()) {
                throw $e;
            }

            $chunk = null;
        }

        if (null === $chunk) {
            $this->ended = true;
        } else {
            $this->buffer .= $chunk;
        }
    }
}
