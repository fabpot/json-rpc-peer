<?php

/*
 * This file is part of the Symfony\Component package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Agent\Tests\Acp;

use Amp\ByteStream\ReadableBuffer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Agent\Acp\JsonRpc\JsonRpcDispatcher;
use Symfony\Component\Agent\Acp\JsonRpc\JsonRpcError;
use Symfony\Component\Agent\Acp\JsonRpc\JsonRpcException;
use Symfony\Component\Agent\Acp\JsonRpc\JsonRpcPeer;
use Symfony\Component\Agent\Acp\JsonRpc\RequestResponder;

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

        $this->assertSame(JsonRpcError::INTERNAL_ERROR, $output[0]['error']['code']);
        $this->assertSame('nope', $output[0]['error']['message']);
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

        $this->assertSame(JsonRpcError::METHOD_NOT_FOUND, $output[0]['error']['code']);
    }

    /**
     * @param callable(JsonRpcDispatcher): void $configure
     *
     * @return list<array<string, mixed>>
     */
    private function drive(string $line, callable $configure): array
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer($line."\n"), $output);
        $dispatcher = new JsonRpcDispatcher($peer);
        $configure($dispatcher);
        $peer->listen();

        return $output->messages();
    }
}
