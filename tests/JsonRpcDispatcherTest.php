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
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcMessage;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use PHPUnit\Framework\TestCase;

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

    public function testRejectsDuplicateRequestHandlerRegistration(): void
    {
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer(''), new CapturingStream()));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('duplicate', static fn(): null => null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A request handler is already registered for method "duplicate".');
        $dispatcher->onRequest('duplicate', static fn(): null => null);
    }

    public function testRejectsDuplicateNotificationHandlerRegistration(): void
    {
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer(''), new CapturingStream()));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onNotification('duplicate', static function (): void {});

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A notification handler is already registered for method "duplicate".');
        $dispatcher->onNotification('duplicate', static function (): void {});
    }

    public function testAllowsRequestAndNotificationHandlersForTheSameMethod(): void
    {
        $output = $this->drive(
            "{\"jsonrpc\":\"2.0\",\"method\":\"same\"}\n{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"same\"}",
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onNotification('same', static function (): void {});
                $dispatcher->onRequest('same', static fn(): string => 'request');
            },
        );

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => 'request']], $output);
    }

    public function testListenWaitsForRequestHandlers(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer('{"jsonrpc":"2.0","id":1,"method":"echo"}'), $output));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('echo', static fn(): string => 'done');

        $peer->listen();

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => 'done']], $output->messages());
    }

    public function testConnectionClosureCancelsActiveRequestHandlers(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":1,"method":"wait"}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('wait', static function (array $params, Cancellation $cancellation): string {
                    try {
                        delay(10, cancellation: $cancellation);
                    } catch (CancelledException) {
                        return 'canceled';
                    }

                    return 'completed';
                });
            },
        );

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => 'canceled']], $output);
    }

    public function testConnectionClosureCancelsConcurrentRequestsSharingAnId(): void
    {
        $output = $this->drive(
            "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"wait\"}\n{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"wait\"}",
            static function (JsonRpcDispatcher $dispatcher): void {
                $count = 0;
                $dispatcher->onRequest('wait', static function (array $params, Cancellation $cancellation) use (&$count): string {
                    $call = ++$count;
                    try {
                        delay(10, cancellation: $cancellation);
                    } catch (CancelledException) {
                        return "canceled-{$call}";
                    }

                    return "completed-{$call}";
                });
            },
        );

        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'canceled-1'],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'canceled-2'],
        ], $output);
    }

    public function testCancelRequestReturnsTheNumberOfMatchingActiveRequests(): void
    {
        $matched = [];
        $output = $this->drive(
            "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"wait\"}\n{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"wait\"}\n{\"jsonrpc\":\"2.0\",\"method\":\"cancel\",\"params\":{\"requestId\":1}}",
            static function (JsonRpcDispatcher $dispatcher) use (&$matched): void {
                $count = 0;
                $dispatcher->onRequest('wait', static function (array $params, Cancellation $cancellation) use (&$count): string {
                    $call = ++$count;
                    try {
                        delay(10, cancellation: $cancellation);
                    } catch (CancelledException) {
                        return "canceled-{$call}";
                    }

                    return "completed-{$call}";
                });
                $dispatcher->onNotification('cancel', static function () use ($dispatcher, &$matched): void {
                    $matched[] = $dispatcher->cancelRequest(1);
                    $matched[] = $dispatcher->cancelRequest(99);
                });
            },
        );

        $this->assertSame([2, 0], $matched);
        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'canceled-1'],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'canceled-2'],
        ], $output);
    }

    public function testHandlerRespondingAfterTheConnectionClosedDoesNotFailTheListener(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer('{"jsonrpc":"2.0","id":1,"method":"wait"}'), $output));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('wait', static function (array $params, Cancellation $cancellation) use ($output): string {
            try {
                delay(10, cancellation: $cancellation);
            } catch (CancelledException) {
                $output->close();

                return 'canceled';
            }

            return 'completed';
        });

        $peer->listen();

        $this->assertSame([], $output->messages());
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

    public function testJsonRpcExceptionWithUnencodableDataBecomesInternalErrorResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"boom","params":{}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('boom', static function (): never {
                    throw new JsonRpcException(-32000, 'app error', ['value' => \INF]);
                });
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 5,
            'error' => ['code' => JsonRpcError::INTERNAL_ERROR, 'message' => 'Internal error'],
        ]], $output);
    }

    public function testUnexpectedExceptionBecomesInternalErrorResponse(): void
    {
        $reported = [];
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"boom","params":{}}',
            static function (JsonRpcDispatcher $dispatcher) use (&$reported): void {
                $dispatcher->onRequest('boom', static function (): never {
                    throw new \RuntimeException('sensitive details');
                });
                $dispatcher->onUnhandledError(static function (\Throwable $error, JsonRpcMessage $message) use (&$reported): void {
                    $reported[] = [$error, $message];
                });
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 5,
            'error' => ['code' => JsonRpcError::INTERNAL_ERROR, 'message' => 'Internal error'],
        ]], $output);
        $this->assertCount(1, $reported);
        $this->assertSame('sensitive details', $reported[0][0]->getMessage());
        $this->assertSame('boom', $reported[0][1]->getMethod());
    }

    public function testNotificationExceptionIsReportedWithoutAResponse(): void
    {
        $reported = [];
        $output = $this->drive(
            '{"jsonrpc":"2.0","method":"boom"}',
            static function (JsonRpcDispatcher $dispatcher) use (&$reported): void {
                $dispatcher->onNotification('boom', static function (): never {
                    throw new \RuntimeException('notification failed');
                });
                $dispatcher->onUnhandledError(static function (\Throwable $error, JsonRpcMessage $message) use (&$reported): void {
                    $reported[] = [$error, $message];
                    throw new \RuntimeException('reporting failed');
                });
            },
        );

        $this->assertSame([], $output);
        $this->assertCount(1, $reported);
        $this->assertSame('notification failed', $reported[0][0]->getMessage());
        $this->assertTrue($reported[0][1]->isNotification());
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
                $dispatcher->onCancel('cancel', 'requestId');
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => ['code' => -32000, 'message' => 'Request canceled.'],
        ]], $output);
    }

    public function testCancellationNotificationUsesConfiguredIdParameter(): void
    {
        $output = $this->drive(
            "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"run\"}\n{\"jsonrpc\":\"2.0\",\"method\":\"$/cancelRequest\",\"params\":{\"id\":9}}",
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('run', static function (array $params, Cancellation $cancellation): never {
                    try {
                        $cancellation->throwIfRequested();
                    } catch (CancelledException) {
                        throw new JsonRpcException(-32001, 'LSP request canceled.');
                    }

                    throw new \LogicException('The request was not canceled.');
                });
                $dispatcher->onCancel('$/cancelRequest', 'id');
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 9,
            'error' => ['code' => -32001, 'message' => 'LSP request canceled.'],
        ]], $output);
    }

    public function testInvalidCancellationIdDoesNotCancelRequest(): void
    {
        $output = $this->drive(
            "{\"jsonrpc\":\"2.0\",\"id\":11,\"method\":\"run\"}\n{\"jsonrpc\":\"2.0\",\"method\":\"cancel\",\"params\":{\"requestId\":{}}}",
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('run', static function (array $params, Cancellation $cancellation): string {
                    $cancellation->throwIfRequested();

                    return 'completed';
                });
                $dispatcher->onCancel('cancel', 'requestId');
            },
        );

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 11, 'result' => 'completed']], $output);
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
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer($input), $output));
        $dispatcher = new JsonRpcDispatcher($peer);
        $configure($dispatcher);
        $peer->listen();

        return $output->messages();
    }
}
