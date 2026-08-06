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

use Amp\ByteStream\Pipe;
use Amp\ByteStream\ReadableBuffer;
use Amp\Cancellation;
use Amp\CancelledException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\Exception\RuntimeException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcMessage;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use Fabpot\JsonRpc\TrafficLoggerInterface;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class JsonRpcDispatcherTest extends TestCase
{
    public function testRequestHandlerReturnValueBecomesResponse(): void
    {
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":1,"method":"echo","params":{"v":42}}',
            static function (JsonRpcDispatcher $dispatcher): void {
                $dispatcher->onRequest('echo', static fn(\stdClass $params): array => ['echoed' => $params->v]);
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
                $dispatcher->onRequest('wait', static function (array|object|null $params, Cancellation $cancellation): string {
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
                $dispatcher->onRequest('wait', static function (array|object|null $params, Cancellation $cancellation) use (&$count): string {
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
                $dispatcher->onRequest('wait', static function (array|object|null $params, Cancellation $cancellation) use (&$count): string {
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
        $dispatcher->onRequest('wait', static function (array|object|null $params, Cancellation $cancellation) use ($output): string {
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
                $dispatcher->onRequest('echo', static fn(\stdClass $params): mixed => $params->v);
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
        $reported = [];
        $output = $this->drive(
            '{"jsonrpc":"2.0","id":5,"method":"boom","params":{}}',
            static function (JsonRpcDispatcher $dispatcher) use (&$reported): void {
                $dispatcher->onRequest('boom', static function (): never {
                    throw new JsonRpcException(-32000, 'app error', ['value' => \INF]);
                });
                $dispatcher->onUnhandledError(static function (\Throwable $error) use (&$reported): void {
                    $reported[] = $error;
                });
            },
        );

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 5,
            'error' => ['code' => JsonRpcError::INTERNAL_ERROR, 'message' => 'Internal error'],
        ]], $output);
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(InvalidArgumentException::class, $reported[0]);
        $this->assertInstanceOf(\JsonException::class, $reported[0]->getPrevious());
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
                $dispatcher->onNotification('session/cancel', static function (\stdClass $params) use (&$seen): void {
                    $seen[] = $params->sessionId;
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
                $dispatcher->onRequest('run', static function (array|object|null $params, Cancellation $cancellation): never {
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
                $dispatcher->onRequest('run', static function (array|object|null $params, Cancellation $cancellation): never {
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
                $dispatcher->onRequest('run', static function (array|object|null $params, Cancellation $cancellation): string {
                    $cancellation->throwIfRequested();

                    return 'completed';
                });
                $dispatcher->onCancel('cancel', 'requestId');
            },
        );

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 11, 'result' => 'completed']], $output);
    }

    public function testRejectsRequestsAboveConcurrencyLimitWithoutBlockingCancellation(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(
            new StreamJsonRpcTransport(new ReadableBuffer(
                '{"jsonrpc":"2.0","id":1,"method":"wait"}' . "\n"
                . '{"jsonrpc":"2.0","method":"cancel","params":{"requestId":1}}' . "\n"
                . '{"jsonrpc":"2.0","id":2,"method":"wait"}'
            ), $output),
            maximumConcurrentInboundRequests: 1,
        );
        $dispatcher = new JsonRpcDispatcher($peer);
        $handled = 0;
        $dispatcher->onRequest('wait', static function (array|object|null $params, Cancellation $cancellation) use (&$handled): string {
            ++$handled;
            try {
                delay(10, cancellation: $cancellation);
            } catch (CancelledException) {
                return 'canceled';
            }

            return 'completed';
        });
        $dispatcher->onCancel('cancel', 'requestId');

        $peer->listen();

        $this->assertSame(1, $handled);
        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => [
                'code' => JsonRpcError::SERVER_OVERLOADED,
                'message' => 'Too many concurrent requests.',
            ],
        ], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 'canceled',
        ]], $output->messages());
    }

    public function testConcurrencyLimitProducesOneCompleteBatchResponse(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(
            new StreamJsonRpcTransport(new ReadableBuffer('[{"jsonrpc":"2.0","id":1,"method":"wait"},{"jsonrpc":"2.0","id":2,"method":"wait"}]'), $output),
            maximumConcurrentInboundRequests: 1,
        );
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('wait', static function (array|object|null $params, Cancellation $cancellation): string {
            try {
                delay(10, cancellation: $cancellation);
            } catch (CancelledException) {
                return 'canceled';
            }

            return 'completed';
        });

        $peer->listen();

        $this->assertSame([[[
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => [
                'code' => JsonRpcError::SERVER_OVERLOADED,
                'message' => 'Too many concurrent requests.',
            ],
        ], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 'canceled',
        ]]], $output->messages());
    }

    public function testConcurrencySlotIsReleasedAfterARequestSettles(): void
    {
        $input = new Pipe(4096);
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(
            new StreamJsonRpcTransport($input->getSource(), $output),
            maximumConcurrentInboundRequests: 1,
        );
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('run', static fn(): string => 'done');
        $listener = async($peer->listen(...));

        $input->getSink()->write('{"jsonrpc":"2.0","id":1,"method":"run"}' . "\n");
        for ($i = 0; $i < 10 && 1 !== \count($output->messages()); ++$i) {
            delay(0);
        }
        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => 'done']], $output->messages());
        delay(0);

        $input->getSink()->write('{"jsonrpc":"2.0","id":2,"method":"run"}' . "\n");
        $input->getSink()->close();
        $listener->await();

        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'done'],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => 'done'],
        ], $output->messages());
    }

    public function testResponseWriteFailureIsNotConvertedToAHandlerError(): void
    {
        $output = new CapturingStream();
        $output->failNextWrite();
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer('{"jsonrpc":"2.0","id":1,"method":"run"}'), $output));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('run', static fn(): string => 'correct');
        $reportedErrors = [];
        $dispatcher->onUnhandledError(static function (\Throwable $e) use (&$reportedErrors): void {
            $reportedErrors[] = $e;
        });

        try {
            $peer->listen();
            $this->fail('The response write failure was not raised.');
        } catch (RuntimeException $e) {
            $this->assertSame('Failed to write to the JSON-RPC connection.', $e->getMessage());
        }

        $this->assertSame([], $reportedErrors);
        $this->assertSame([], $output->messages());
    }

    public function testResponseLoggerFailureIsNotConvertedToAHandlerError(): void
    {
        $failure = new \LogicException('Logger failed.');
        $logger = new class ($failure) implements TrafficLoggerInterface {
            public function __construct(
                private readonly \Throwable $failure,
            ) {}

            public function logInbound(string $line): void {}

            public function logOutbound(string $line): void
            {
                throw $this->failure;
            }
        };
        $peer = new JsonRpcPeer(
            new StreamJsonRpcTransport(new ReadableBuffer('{"jsonrpc":"2.0","id":1,"method":"run"}'), new CapturingStream()),
            $logger,
        );
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('run', static fn(): string => 'correct');
        $reportedErrors = [];
        $dispatcher->onUnhandledError(static function (\Throwable $e) use (&$reportedErrors): void {
            $reportedErrors[] = $e;
        });

        try {
            $peer->listen();
            $this->fail('The logger failure was not raised.');
        } catch (\LogicException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame([], $reportedErrors);
    }

    public function testOversizedBatchResponseFailsTheListenerWithoutRetrying(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(
            new ReadableBuffer('[{"jsonrpc":"2.0","id":1,"method":"first"},{"jsonrpc":"2.0","id":2,"method":"second"}]'),
            $output,
            maximumMessageBytes: 150,
        ));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('first', static fn(): string => str_repeat('x', 80));
        $dispatcher->onRequest('second', static fn(): string => str_repeat('x', 80));
        $reportedErrors = [];
        $dispatcher->onUnhandledError(static function (\Throwable $e) use (&$reportedErrors): void {
            $reportedErrors[] = $e;
        });

        try {
            $peer->listen();
            $this->fail('The oversized batch response was not raised.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('The JSON-RPC message exceeds the configured limit.', $e->getMessage());
        }

        $this->assertSame([], $reportedErrors);
        $this->assertSame([], $output->messages());
    }

    public function testListenWaitsForSiblingHandlersAfterAResponseFailure(): void
    {
        $output = new CapturingStream();
        $output->failNextWrite();
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(new ReadableBuffer(
            '{"jsonrpc":"2.0","id":1,"method":"first"}' . "\n"
            . '{"jsonrpc":"2.0","id":2,"method":"second"}'
        ), $output));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('first', static fn(): string => 'first');
        $secondFinished = false;
        $dispatcher->onRequest('second', static function () use (&$secondFinished): string {
            delay(0.01);
            $secondFinished = true;

            return 'second';
        });

        try {
            $peer->listen();
            $this->fail('The response write failure was not raised.');
        } catch (RuntimeException) {
        }

        $this->assertTrue($secondFinished);
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
