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

/** @internal */
final class DeterministicChunkings
{
    private function __construct() {}

    /**
     * @return iterable<string, list<string>>
     */
    public static function of(string $contents): iterable
    {
        yield 'whole buffer' => [$contents];
        yield 'one byte per chunk' => str_split($contents);

        $length = \strlen($contents);
        for ($offset = 1; $offset < $length; ++$offset) {
            yield "single split at {$offset}" => [
                substr($contents, 0, $offset),
                substr($contents, $offset),
            ];
        }

        for ($case = 1; $case <= 64; ++$case) {
            $state = $case;
            $offset = 0;
            $chunks = [];
            while ($offset < $length) {
                $state = ($state * 48271) % 2147483647;
                $size = 1 + ($state % 17);
                $chunks[] = substr($contents, $offset, $size);
                $offset += $size;
            }

            yield "partition {$case}" => $chunks;
        }
    }
}
