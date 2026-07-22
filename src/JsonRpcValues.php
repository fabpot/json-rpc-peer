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

    public static function isSafeFloatId(float $id): bool
    {
        return is_finite($id) && ($id !== floor($id) || ($id >= self::SAFE_INTEGER_MIN && $id <= self::SAFE_INTEGER_MAX));
    }

    public static function containsNonFiniteFloat(mixed $value): bool
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
