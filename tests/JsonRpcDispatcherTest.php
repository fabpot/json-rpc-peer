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
use PHPUnit\Framework\TestCase;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\RequestResponder;

final class JsonRpcDispatcherTest extends TestCase
{
    public function testRequestHandlerResolvesResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":1,"method":"echo","params":{"v":42}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('echo', static function (array $params, RequestResponder $r): void {
                    $r->resolve(['echoed' => $params['v']]);
                });
            },
        );

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => ['echoed' => 42]]], $output);
    }

    public function testThrownJsonRpcExceptionBecomesErrorResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"boom","params":{}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('boom', static function (): void {
                    throw new JsonRpcException(JsonRpcError::INTERNAL_ERROR, 'nope');
                });
            },
        );

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 5,
            'error' => ['code' => JsonRpcError::INTERNAL_ERROR, 'message' => 'nope'],
        ], $output[0]);
    }

    public function testUnexpectedExceptionBecomesInternalErrorResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"boom","params":{}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('boom', static function (): void {
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

    public function testResponderSettlesOnlyOnce(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"settle","params":{}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('settle', static function (array $params, RequestResponder $responder): void {
                    $responder->resolve('first');
                    $responder->reject(JsonRpcError::INTERNAL_ERROR, 'second');
                });
            },
        );

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 5, 'result' => 'first']], $output);
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

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":3,"method":"missing","params":{}}',
            static function (): void {},
        );

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => ['code' => JsonRpcError::METHOD_NOT_FOUND, 'message' => 'Method not found: missing'],
        ], $output[0]);
    }

    /**
     * @param callable(JsonRpcDispatcher): void $configure
     *
     * @return list<array<string, mixed>>
     */
    private function drive(string $line, callable $configure): array
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer($line . "\n"), $output);
        $dispatcher = new JsonRpcDispatcher($peer);
        $configure($dispatcher);
        $peer->listen();

        return $output->messages();
    }
}
