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

/** @internal */
final class JsonRpcValues
{
    private const SAFE_INTEGER_MIN = -9_007_199_254_740_991;
    private const SAFE_INTEGER_MAX = 9_007_199_254_740_991;

    private function __construct() {}

    public static function requestKey(int|float|string|null $id): string
    {
        if (\is_float($id) && $id === floor($id) && $id >= self::SAFE_INTEGER_MIN && $id <= self::SAFE_INTEGER_MAX) {
            $id = (int) $id;
        }

        return get_debug_type($id) . ':' . $id;
    }

    public static function isValidId(mixed $id): bool
    {
        return null === $id || \is_string($id) || ((\is_int($id) || \is_float($id)) && self::isSafeNumberId($id));
    }

    public static function isSafeNumberId(int|float $id): bool
    {
        if (\is_int($id)) {
            return $id >= self::SAFE_INTEGER_MIN && $id <= self::SAFE_INTEGER_MAX;
        }

        return is_finite($id) && ($id !== floor($id) || ($id >= self::SAFE_INTEGER_MIN && $id <= self::SAFE_INTEGER_MAX));
    }

    /**
     * @param array<array-key, mixed>|object|null $params
     *
     * @return array<array-key, mixed>|object|null
     */
    public static function normalizeParams(array|object|null $params): array|object|null
    {
        if (null === $params || \is_array($params)) {
            return $params;
        }

        /** @var \SplObjectStorage<object, null> $serializedObjects */
        $serializedObjects = new \SplObjectStorage();
        $serializedObjectCount = 0;
        while ($params instanceof \JsonSerializable) {
            if (++$serializedObjectCount > 512 || $serializedObjects->offsetExists($params)) {
                throw new InvalidArgumentException('JSON-RPC params must encode as an array or object.');
            }
            $serializedObjects->offsetSet($params);
            $params = $params->jsonSerialize();
        }

        if (!\is_array($params) && (!\is_object($params) || $params instanceof \UnitEnum)) {
            throw new InvalidArgumentException('JSON-RPC params must encode as an array or object.');
        }

        return $params;
    }

    public static function decodeInbound(mixed $value, JsonRpcValueDecoding $decoding): mixed
    {
        if (JsonRpcValueDecoding::PreserveShapes === $decoding) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::decodeInbound($item, $decoding);
        }

        return $value;
    }

    public static function containsNonFiniteFloat(mixed $value): bool
    {
        if (\is_float($value)) {
            return !is_finite($value);
        }

        if (\is_object($value)) {
            $value = get_object_vars($value);
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
