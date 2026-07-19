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

use Fabpot\JsonRpc\Exception\InvalidArgumentException;

/**
 * A validated inbound JSON-RPC 2.0 request or notification.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcMessage
{
    /**
     * @param array<array-key, mixed> $params
     */
    private function __construct(
        private readonly int|float|string|null $id,
        private readonly bool $hasId,
        private readonly string $method,
        private readonly array $params,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if ('2.0' !== ($data['jsonrpc'] ?? null)) {
            throw new InvalidArgumentException('The jsonrpc member must be "2.0".');
        }

        if (!isset($data['method']) || !\is_string($data['method'])) {
            throw new InvalidArgumentException('The method member must be a string.');
        }

        $params = [];
        if (\array_key_exists('params', $data)) {
            if (!\is_array($data['params'])) {
                throw new InvalidArgumentException('The params member must be an array or object.');
            }
            if (self::containsNonFiniteFloat($data['params'])) {
                throw new InvalidArgumentException('The params member must contain only finite numbers.');
            }
            $params = $data['params'];
        }

        $hasId = \array_key_exists('id', $data);
        $id = $data['id'] ?? null;
        if ($hasId && !\is_int($id) && !\is_string($id) && null !== $id && (!\is_float($id) || !self::isSafeFloatId($id))) {
            throw new InvalidArgumentException('The id member must be a safely representable finite number, string, or null.');
        }
        /** @var int|float|string|null $id */

        return new self($id, $hasId, $data['method'], $params);
    }

    public function getId(): int|float|string|null
    {
        return $this->id;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function isNotification(): bool
    {
        return !$this->hasId;
    }

    private static function isSafeFloatId(float $id): bool
    {
        return is_finite($id) && ($id !== floor($id) || ($id >= -9_007_199_254_740_991 && $id <= 9_007_199_254_740_991));
    }

    private static function containsNonFiniteFloat(mixed $value): bool
    {
        if (\is_float($value)) {
            return !is_finite($value);
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsNonFiniteFloat($item)) {
                return true;
            }
        }

        return false;
    }
}
