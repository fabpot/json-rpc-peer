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

use Symfony\Component\Filesystem\Filesystem;

/**
 * Appends redacted JSON-RPC traffic to a file for post-hoc debugging.
 *
 * Enabled by the VOID_ACP_TRAFFIC_LOG environment variable, which names the
 * target log file. Secrets are redacted before every line is written.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class FileTrafficLogger implements TrafficLoggerInterface
{
    /**
     * Keys whose values are redacted wherever they appear in a message.
     */
    private const REDACT_KEYS = ['env', 'headers', 'authorization', 'apiKey', 'api_key', 'token', 'accessToken', 'password', 'secret'];

    public function __construct(
        private readonly string $path,
    ) {
        new Filesystem()->mkdir(\dirname($path));
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
        $redacted = $this->redact($line);
        file_put_contents(
            $this->path,
            \sprintf("%s %s %s\n", new \DateTimeImmutable()->format('c'), $direction, $redacted),
            \FILE_APPEND | \LOCK_EX,
        );
    }

    private function redact(string $line): string
    {
        try {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $line;
        }

        $redacted = $this->redactValue($decoded);

        return json_encode($redacted, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: $line;
    }

    private function redactValue(mixed $value): mixed
    {
        if (!\is_array($value)) {
            if (\is_string($value) && $this->looksLikeCredentialUrl($value)) {
                return $this->redactUrl($value);
            }

            return $value;
        }

        $result = [];
        foreach ($value as $key => $child) {
            if (\is_string($key) && \in_array(strtolower($key), array_map('strtolower', self::REDACT_KEYS), true)) {
                $result[$key] = '[redacted]';
                continue;
            }
            $result[$key] = $this->redactValue($child);
        }

        return $result;
    }

    private function looksLikeCredentialUrl(string $value): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.-]*://[^/@\s]+@#i', $value);
    }

    private function redactUrl(string $value): string
    {
        return preg_replace('#(://)[^/@\s]+@#', '$1[redacted]@', $value) ?? $value;
    }
}
