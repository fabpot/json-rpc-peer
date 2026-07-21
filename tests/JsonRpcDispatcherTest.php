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
use Amp\Cancellation;
use Amp\CancelledException;
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcPeer;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Amp\delay;

final class JsonRpcDispatcherTest extends TestCase
{
    public function testRequestHandlerReturnValueBecomesResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":1,"method":"echo","params":{"v":42}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('echo', static fn(array $params): array => ['echoed' => $params['v']]);
            },
        );

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => ['echoed' => 42]]], $output);
    }

    public function testOneElementBatchReturnsResponseArray(): void
    {
        $output = $this->drive(
            '[{"jsonrpc":"2.0","id":1,"method":"echo","params":{"v":42}}]',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('echo', static fn(array $params): mixed => $params['v']);
            },
        );

        $this->assertSame([[['jsonrpc' => '2.0', 'id' => 1, 'result' => 42]]], $output);
    }

    public function testThrownJsonRpcExceptionBecomesErrorResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"boom","params":{}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('boom', static function (): never {
                    throw new JsonRpcException(JsonRpcError::INTERNAL_ERROR, 'nope');
                });
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 5,
            'error' => ['code' => JsonRpcError::INTERNAL_ERROR, 'message' => 'nope'],
        ]], $output);
    }

    public function testUnexpectedExceptionBecomesInternalErrorResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"boom","params":{}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('boom', static function (): never {
                    throw new \RuntimeException('sensitive details');
                });
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 5,
            'error' => ['code' => JsonRpcError::INTERNAL_ERROR, 'message' => 'Internal error'],
        ]], $output);
    }

    public function testNotificationHandlerProducesNoResponse(): void
    {
        $seen = [];
        $output = $this->drive(
            '{"jsonrpc":"2.0","method":"session/cancel","params":{"sessionId":"s1"}}',
            static function (JsonRpcDispatcher $dispatcher) use (&$seen): void {
                $dispatcher->onNotification('session/cancel', static function (array $params) use (&$seen): void {
                    $seen[] = $params['sessionId'];
                });
            },
        );

        $this->assertSame([], $output, 'A notification must not produce any JSON-RPC response.');
        $this->assertSame(['s1'], $seen);
    }

    public function testRequestHandlersRunConcurrently(): void
    {
        $output = $this->drive(
            "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"slow\"}\n{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"fast\"}",
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('slow', static function (): string {
                    delay(0.001);

                    return 'slow';
                });
                $dispatcher->onRequest('fast', static fn(): string => 'fast');
            },
        );

        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => 'fast'],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'slow'],
        ], $output);
    }

    public function testCancellationNotificationCancelsMatchingRequest(): void
    {
        $output = $this->drive(
            "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"run\"}\n{\"jsonrpc\":\"2.0\",\"method\":\"cancel\",\"params\":{\"requestId\":7}}",
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('run', static function (array $params, Cancellation $cancellation): never {
                    try {
                        $cancellation->throwIfRequested();
                    } catch (CancelledException) {
                        throw new JsonRpcException(-32000, 'Request canceled.');
                    }

                    throw new \LogicException('The request was not canceled.');
                });
                $dispatcher->onNotification('cancel', static function (array $params) use ($dispatcher): void {
                    /** @var int|float|string|null $requestId */
                    $requestId = $params['requestId'];
                    $dispatcher->cancelRequest($requestId);
                });
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => ['code' => -32000, 'message' => 'Request canceled.'],
        ]], $output);
    }

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":3,"method":"missing","params":{}}',
            static function (): void {},
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => ['code' => JsonRpcError::METHOD_NOT_FOUND, 'message' => 'Method not found: missing'],
        ]], $output);
    }

    /**
     * @param callable(JsonRpcDispatcher): void $configure
     *
     * @return list<array<array-key, mixed>>
     */
    private function drive(string $input, callable $configure): array
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer($input), $output);
        $dispatcher = new JsonRpcDispatcher($peer);
        $configure($dispatcher);
        $peer->listen();
        EventLoop::run();

        return $output->messages();
    }
}
