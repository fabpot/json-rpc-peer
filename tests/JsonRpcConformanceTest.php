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

use Amp\ByteStream\ReadableBuffer;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the JSON-RPC 2.0 examples published in section 7 of the specification.
 *
 * @see https://www.jsonrpc.org/specification#examples
 */
final class JsonRpcConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array<array-key, mixed>>}>
     */
    public static function singleMessageProvider(): iterable
    {
        yield 'positional parameters' => [
            '{"jsonrpc":"2.0","method":"subtract","params":[42,23],"id":1}',
            [['jsonrpc' => '2.0', 'id' => 1, 'result' => 19]],
        ];
        yield 'named parameters' => [
            '{"jsonrpc":"2.0","method":"subtract","params":{"subtrahend":23,"minuend":42},"id":3}',
            [['jsonrpc' => '2.0', 'id' => 3, 'result' => 19]],
        ];
        yield 'notification' => [
            '{"jsonrpc":"2.0","method":"update","params":[1,2,3,4,5]}',
            [],
        ];
        yield 'notification without parameters' => [
            '{"jsonrpc":"2.0","method":"foobar"}',
            [],
        ];
        yield 'unknown method' => [
            '{"jsonrpc":"2.0","method":"foobar","id":"1"}',
            [[
                'jsonrpc' => '2.0',
                'id' => '1',
                'error' => ['code' => JsonRpcError::METHOD_NOT_FOUND, 'message' => 'Method not found'],
            ]],
        ];
        yield 'invalid JSON' => [
            '{"jsonrpc":"2.0","method":"foobar,"params":"bar","baz]',
            [[
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => JsonRpcError::PARSE_ERROR, 'message' => 'Parse error'],
            ]],
        ];
        yield 'invalid request' => [
            '{"jsonrpc":"2.0","method":1,"params":"bar"}',
            [[
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
            ]],
        ];
    }

    /**
     * @param list<array<array-key, mixed>> $expected
     */
    #[DataProvider('singleMessageProvider')]
    public function testOfficialSingleMessageExamples(string $message, array $expected): void
    {
        $this->assertSame($expected, $this->exchange($message));
    }

    public function testOfficialMalformedBatchExample(): void
    {
        $messages = $this->exchange(
            '[{"jsonrpc":"2.0","method":"sum","params":[1,2,4],"id":"1"},'
            . '{"jsonrpc":"2.0","method"',
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::PARSE_ERROR, 'message' => 'Parse error'],
        ]], $messages);
    }

    public function testOfficialEmptyBatchExample(): void
    {
        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
        ]], $this->exchange('[]'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidBatchProvider(): iterable
    {
        yield 'one entry' => ['[1]', 1];
        yield 'three entries' => ['[1,2,3]', 3];
    }

    #[DataProvider('invalidBatchProvider')]
    public function testOfficialInvalidBatchExamples(string $message, int $errorCount): void
    {
        $error = [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
        ];

        $this->assertSame([[...array_fill(0, $errorCount, $error)]], $this->exchange($message));
    }

    public function testOfficialMixedBatchExample(): void
    {
        $messages = $this->exchange(
            '[{"jsonrpc":"2.0","method":"sum","params":[1,2,4],"id":"1"},'
            . '{"jsonrpc":"2.0","method":"notify_hello","params":[7]},'
            . '{"jsonrpc":"2.0","method":"subtract","params":[42,23],"id":"2"},'
            . '{"foo":"boo"},'
            . '{"jsonrpc":"2.0","method":"foo.get","params":{"name":"myself"},"id":"5"},'
            . '{"jsonrpc":"2.0","method":"get_data","id":"9"}]',
        );

        $this->assertCount(1, $messages);
        /** @var list<array<string, mixed>> $responses */
        $responses = $messages[0];
        usort($responses, static fn(array $left, array $right): int => strcmp(serialize($left['id'] ?? null), serialize($right['id'] ?? null)));
        $this->assertSame([
            [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
            ],
            ['jsonrpc' => '2.0', 'id' => '1', 'result' => 7],
            ['jsonrpc' => '2.0', 'id' => '2', 'result' => 19],
            [
                'jsonrpc' => '2.0',
                'id' => '5',
                'error' => ['code' => JsonRpcError::METHOD_NOT_FOUND, 'message' => 'Method not found'],
            ],
            ['jsonrpc' => '2.0', 'id' => '9', 'result' => ['hello', 5]],
        ], $responses);
    }

    public function testOfficialNotificationOnlyBatchExample(): void
    {
        $messages = $this->exchange(
            '[{"jsonrpc":"2.0","method":"notify_sum","params":[1,2,4]},'
            . '{"jsonrpc":"2.0","method":"notify_hello","params":[7]}]',
        );

        $this->assertSame([], $messages);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function exchange(string $message): array
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer($message), $output));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('subtract', static function (array|\stdClass|null $params): int {
            if (\is_array($params)) {
                $minuend = $params[0] ?? null;
                $subtrahend = $params[1] ?? null;
            } elseif ($params instanceof \stdClass) {
                $minuend = $params->minuend ?? null;
                $subtrahend = $params->subtrahend ?? null;
            } else {
                throw new \InvalidArgumentException('Expected subtraction parameters.');
            }
            if (!\is_int($minuend) || !\is_int($subtrahend)) {
                throw new \InvalidArgumentException('Expected integer subtraction parameters.');
            }

            return $minuend - $subtrahend;
        });
        $dispatcher->onRequest('sum', static fn(array $params): int|float => array_sum($params));
        $dispatcher->onRequest('get_data', static fn(): array => ['hello', 5]);
        $dispatcher->onNotification('update', static function (): void {});
        $dispatcher->onNotification('foobar', static function (): void {});
        $dispatcher->onNotification('notify_sum', static function (): void {});
        $dispatcher->onNotification('notify_hello', static function (): void {});

        $peer->listen();

        return $output->messages();
    }
}
