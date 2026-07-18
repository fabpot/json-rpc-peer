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
 * Appends redacted JSON-RPC traffic to a file for post-hoc debugging.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class FileTrafficLogger implements TrafficLoggerInterface
{
    private const REDACT_KEYS = [
        'env' => true,
        'headers' => true,
        'authorization' => true,
        'apikey' => true,
        'api_key' => true,
        'token' => true,
        'accesstoken' => true,
        'password' => true,
        'secret' => true,
    ];

    public function __construct(
        private readonly string $path,
    ) {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create the traffic log directory "%s".', $directory));
        }
    }

    public static function fromEnv(): ?self
    {
        $path = getenv('VOID_ACP_TRAFFIC_LOG');
        if (false === $path || '' === $path) {
            return null;
        }

        return new self($path);
    }

    public function logInbound(string $line): void
    {
        $this->write('>>', $line);
    }

    public function logOutbound(string $line): void
    {
        $this->write('<<', $line);
    }

    private function write(string $direction, string $line): void
    {
        $written = file_put_contents(
            $this->path,
            \sprintf("%s %s %s\n", new \DateTimeImmutable()->format('c'), $direction, $this->redact($line)),
            \FILE_APPEND | \LOCK_EX,
        );

        if (false === $written) {
            throw new \RuntimeException(\sprintf('Unable to write to the traffic log "%s".', $this->path));
        }
    }

    private function redact(string $line): string
    {
        try {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $redacted = $this->redactValue($decoded);

            return json_encode($redacted, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return $line;
        }
    }

    private function redactValue(mixed $value): mixed
    {
        if (\is_string($value)) {
            return preg_replace('#(://)[^/@\s]+@#', '$1[redacted]@', $value) ?? $value;
        }

        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $child) {
            if (\is_string($key) && isset(self::REDACT_KEYS[strtolower($key)])) {
                $result[$key] = '[redacted]';
            } else {
                $result[$key] = $this->redactValue($child);
            }
        }

        return $result;
    }
}
