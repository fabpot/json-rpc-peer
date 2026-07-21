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

final class FileTrafficLogger implements TrafficLoggerInterface
{
    private const REDACTED = '[redacted]';

    /** @var array<string, true> */
    private readonly array $sensitiveKeys;

    /**
     * @param list<string> $sensitiveKeys
     */
    public function __construct(
        private readonly string $path,
        array $sensitiveKeys = ['authorization', 'apiKey', 'api_key', 'accessToken', 'token', 'password', 'secret'],
    ) {
        $keys = [];
        foreach ($sensitiveKeys as $key) {
            $keys[strtolower($key)] = true;
        }
        $this->sensitiveKeys = $keys;

        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create traffic log directory "%s".', $directory));
        }
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
        $written = @file_put_contents(
            $this->path,
            \sprintf("%s %s %s\n", new \DateTimeImmutable()->format('c'), $direction, $this->redact($line)),
            \FILE_APPEND | \LOCK_EX,
        );
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Unable to write JSON-RPC traffic log "%s".', $this->path));
        }
    }

    private function redact(string $line): string
    {
        try {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $line;
        }

        return json_encode($this->redactValue($decoded), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: $line;
    }

    private function redactValue(mixed $value): mixed
    {
        if (!\is_array($value)) {
            if (\is_string($value) && preg_match('#^[a-z][a-z0-9+.-]*://[^/@\s]+@#i', $value)) {
                return preg_replace('#(://)[^/@\s]+@#', '$1' . self::REDACTED . '@', $value) ?? $value;
            }

            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $child) {
            if (\is_string($key) && isset($this->sensitiveKeys[strtolower($key)])) {
                $redacted[$key] = self::REDACTED;
            } else {
                $redacted[$key] = $this->redactValue($child);
            }
        }

        return $redacted;
    }
}
