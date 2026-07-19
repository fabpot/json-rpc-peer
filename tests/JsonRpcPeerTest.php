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
use Fabpot\JsonRpc\BatchNotification;
use Fabpot\JsonRpc\BatchRequest;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\ExceptionInterface;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\InvalidResponseException;
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Fabpot\JsonRpc\JsonRpcMessage;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\RequestResponder;

final class JsonRpcPeerTest extends TestCase
{
    public function testDispatchesInboundRequestsToTheMessageHandler(): void
    {
        $input = new ReadableBuffer(
            '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"x":1}}' . "\n"
            . '{"jsonrpc":"2.0","method":"note","params":{}}' . "\n"
        );
        $output = new CapturingStream();
        $peer = new JsonRpcPeer($input, $output);

        /** @var list<JsonRpcMessage> $received */
        $received = [];
        $peer->onMessage(static function (JsonRpcMessage $m) use (&$received): void {
            $received[] = $m;
        });
        $peer->listen();

        $this->assertCount(2, $received);
        $this->assertSame('ping', $received[0]->getMethod());
        $this->assertSame(1, $received[0]->getId());
        $this->assertSame(['x' => 1], $received[0]->getParams());
        $this->assertTrue($received[1]->isNotification());
    }

    /**
     * @return iterable<string, array{string, int|float|string|null}>
     */
    public static function invalidRequestProvider(): iterable
    {
        yield 'wrong version' => ['{"jsonrpc":"1.0","id":1,"method":"ping"}', 1];
        yield 'missing method' => ['{"jsonrpc":"2.0","id":2,"params":{}}', 2];
        yield 'non-string method' => ['{"jsonrpc":"2.0","id":3,"method":42}', 3];
        yield 'scalar params' => ['{"jsonrpc":"2.0","id":4,"method":"ping","params":42}', 4];
        yield 'null params' => ['{"jsonrpc":"2.0","id":4,"method":"ping","params":null}', 4];
        yield 'invalid id' => ['{"jsonrpc":"2.0","id":{},"method":"ping"}', null];
        yield 'non-finite id' => ['{"jsonrpc":"2.0","id":1e400,"method":"ping"}', null];
    }

    #[DataProvider('invalidRequestProvider')]
    public function testRejectsInvalidRequests(string $line, int|float|string|null $expectedId): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer($line . "\n"), $output);
        $handled = false;
        $peer->onMessage(static function () use (&$handled): void {
            $handled = true;
        });

        $peer->listen();

        $this->assertFalse($handled);
        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => $expectedId,
            'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
        ]], $output->messages());
    }

    public function testEmptyBatchProducesSingleInvalidRequest(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer("[]\n"), $output);

        $peer->listen();

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
        ]], $output->messages());
    }

    public function testExplicitNullIdIsARequest(): void
    {
        $peer = new JsonRpcPeer(new ReadableBuffer('{"jsonrpc":"2.0","id":null,"method":"ping"}'), new CapturingStream());
        $received = null;
        $peer->onMessage(static function (JsonRpcMessage $message) use (&$received): void {
            $received = $message;
        });

        $peer->listen();

        $this->assertInstanceOf(JsonRpcMessage::class, $received);
        $this->assertFalse($received->isNotification());
        $this->assertNull($received->getId());
    }

    public function testRespondsWithResultAndNotifies(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer(''), $output);

        $peer->respond(7, ['ok' => true]);
        $peer->notify('session/update', ['sessionId' => 's1']);
        $peer->notify('shutdown');

        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]],
            ['jsonrpc' => '2.0', 'method' => 'session/update', 'params' => ['sessionId' => 's1']],
            ['jsonrpc' => '2.0', 'method' => 'shutdown'],
        ], $output->messages());
    }

    public function testDispatchesMixedBatchAndReturnsResponseArray(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer('[{"jsonrpc":"2.0","id":1,"method":"first"},{"jsonrpc":"2.0","method":"note"},42,{"jsonrpc":"2.0","id":2,"method":"second"}]'), $output);
        $notificationSeen = false;
        $peer->onMessage(static function (JsonRpcMessage $message, ?RequestResponder $responder) use (&$notificationSeen): void {
            if ($message->isNotification()) {
                $notificationSeen = true;

                return;
            }

            $responder?->resolve($message->getMethod());
        });

        $peer->listen();

        $this->assertTrue($notificationSeen);
        $this->assertSame([[[
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 'first',
        ], [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
        ], [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => 'second',
        ]]], $output->messages());
    }

    public function testBatchReturnsOneErrorForEachInvalidEntry(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer('[1,[],{"jsonrpc":"2.0","id":7}]'), $output);

        $peer->listen();

        $error = [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::INVALID_REQUEST, 'message' => 'Invalid Request'],
        ];
        $this->assertSame([[...array_fill(0, 3, $error)]], $output->messages());
    }

    public function testNotificationOnlyBatchProducesNoResponse(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer('[{"jsonrpc":"2.0","method":"first"},{"jsonrpc":"2.0","method":"second"}]'), $output);
        $seen = [];
        $peer->onMessage(static function (JsonRpcMessage $message) use (&$seen): void {
            $seen[] = $message->getMethod();
        });

        $peer->listen();

        $this->assertSame(['first', 'second'], $seen);
        $this->assertSame([], $output->messages());
    }

    public function testBatchNotificationFailureDoesNotPreventSiblingRequests(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer('[{"jsonrpc":"2.0","method":"note"},{"jsonrpc":"2.0","id":1,"method":"request"}]'), $output);
        $peer->onMessage(static function (JsonRpcMessage $message, ?RequestResponder $responder): void {
            if ($message->isNotification()) {
                throw new \RuntimeException('notification failed');
            }

            $responder?->resolve('ok');
        });

        $peer->listen();

        $this->assertSame([[[
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 'ok',
        ]]], $output->messages());
    }

    public function testBatchWaitsForDeferredResponsesAndUsesSettlementOrder(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer('[{"jsonrpc":"2.0","id":1,"method":"first"},{"jsonrpc":"2.0","id":2,"method":"second"}]'), $output);
        $responders = [];
        $peer->onMessage(static function (JsonRpcMessage $message, ?RequestResponder $responder) use (&$responders): void {
            $responders[$message->getMethod()] = $responder;
        });

        $peer->listen();

        $this->assertSame([], $output->messages());
        $responders['second']?->resolve(2);
        $this->assertSame([], $output->messages());
        $responders['first']?->resolve(1);
        $this->assertSame([[[
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => 2,
        ], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 1,
        ]]], $output->messages());
    }

    public function testOutboundRequestsResolveOutOfOrder(): void
    {
        $input = new ReadableBuffer(
            '{"jsonrpc":"2.0","id":2,"result":"second"}' . "\n"
            . '{"jsonrpc":"2.0","id":1,"result":"first"}' . "\n"
        );
        $output = new CapturingStream();
        $peer = new JsonRpcPeer($input, $output);

        $first = $peer->request('first', ['value' => 1]);
        $second = $peer->request('second');
        $peer->listen();

        $this->assertSame('first', $first->await());
        $this->assertSame('second', $second->await());
        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'first', 'params' => ['value' => 1]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'second'],
        ], $output->messages());
    }

    public function testWritesMixedOutboundBatchAndReturnsRequestFutures(): void
    {
        $input = new ReadableBuffer('[{"jsonrpc":"2.0","id":2,"result":"second"},{"jsonrpc":"2.0","id":1,"result":"first"}]');
        $output = new CapturingStream();
        $peer = new JsonRpcPeer($input, $output);

        $responses = $peer->batch(
            new BatchRequest('first', ['value' => 1]),
            new BatchNotification('note'),
            new BatchRequest('second'),
        );
        $peer->listen();

        $this->assertSame('first', $responses[0]->await());
        $this->assertSame('second', $responses[1]->await());
        $this->assertSame([[['jsonrpc' => '2.0', 'method' => 'first', 'params' => ['value' => 1], 'id' => 1], ['jsonrpc' => '2.0', 'method' => 'note'], ['jsonrpc' => '2.0', 'method' => 'second', 'id' => 2]]], $output->messages());
    }

    public function testRejectsEmptyOutboundBatch(): void
    {
        $peer = new JsonRpcPeer(new ReadableBuffer(''), new CapturingStream());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A JSON-RPC batch must contain at least one entry.');
        $peer->batch();
    }

    public function testOutboundBatchEncodingFailureThrowsInvalidArgumentException(): void
    {
        $peer = new JsonRpcPeer(new ReadableBuffer(''), new CapturingStream());

        $this->expectException(InvalidArgumentException::class);
        $peer->batch(new BatchRequest('invalid', ['value' => \INF]));
    }

    public function testOutboundBatchWriteFailureThrowsConnectionClosedException(): void
    {
        $output = new CapturingStream();
        $output->close();
        $peer = new JsonRpcPeer(new ReadableBuffer(''), $output);

        $this->expectException(ConnectionClosedException::class);
        $peer->batch(new BatchRequest('first'), new BatchNotification('note'));
    }

    public function testInboundResponseBatchSettlesMatchingRequests(): void
    {
        $input = new ReadableBuffer('[{"jsonrpc":"2.0","id":2,"result":"second"},{"jsonrpc":"1.0","id":1,"result":"invalid"},{"jsonrpc":"2.0","id":99,"result":"unknown"}]');
        $peer = new JsonRpcPeer($input, new CapturingStream());
        $first = $peer->request('first');
        $second = $peer->request('second');

        $peer->listen();

        $this->assertSame('second', $second->await());
        $this->expectException(InvalidResponseException::class);
        $first->await();
    }

    public function testResponseWithFloatIdResolvesTheMatchingIntRequest(): void
    {
        $input = new ReadableBuffer('{"jsonrpc":"2.0","id":1.0,"result":"ok"}');
        $peer = new JsonRpcPeer($input, new CapturingStream());
        $response = $peer->request('ping');

        $peer->listen();

        $this->assertSame('ok', $response->await());
    }

    public function testRequestOnClosedOutputThrowsConnectionClosedException(): void
    {
        $output = new CapturingStream();
        $output->close();
        $peer = new JsonRpcPeer(new ReadableBuffer(''), $output);

        $this->expectException(ConnectionClosedException::class);
        $peer->request('ping');
    }

    public function testOutboundRequestFailsWithRemoteError(): void
    {
        $input = new ReadableBuffer('{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"Bad params","data":{"field":"value"}}}');
        $peer = new JsonRpcPeer($input, new CapturingStream());
        $response = $peer->request('fail');

        $peer->listen();

        try {
            $response->await();
            $this->fail('The remote error was not raised.');
        } catch (JsonRpcException $e) {
            $this->assertInstanceOf(ExceptionInterface::class, $e);
            $this->assertSame(JsonRpcError::INVALID_PARAMS, $e->getCode());
            $this->assertSame('Bad params', $e->getMessage());
            $this->assertSame(['field' => 'value'], $e->getData());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidResponseProvider(): iterable
    {
        yield 'wrong version' => ['{"jsonrpc":"1.0","id":1,"result":"invalid"}'];
        yield 'missing result and error' => ['{"jsonrpc":"2.0","id":1}'];
        yield 'result and error' => ['{"jsonrpc":"2.0","id":1,"result":null,"error":{"code":-32603,"message":"error"}}'];
        yield 'invalid error' => ['{"jsonrpc":"2.0","id":1,"error":{"code":"invalid","message":"error"}}'];
    }

    #[DataProvider('invalidResponseProvider')]
    public function testInvalidResponseFailsItsRequest(string $line): void
    {
        $peer = new JsonRpcPeer(new ReadableBuffer($line), new CapturingStream());
        $response = $peer->request('fail');

        $peer->listen();

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('Received an invalid JSON-RPC response.');
        $response->await();
    }

    public function testConnectionCloseFailsPendingRequests(): void
    {
        $peer = new JsonRpcPeer(new ReadableBuffer(''), new CapturingStream());
        $response = $peer->request('never-answered');

        $peer->listen();

        $this->expectException(ConnectionClosedException::class);
        $this->expectExceptionMessage('The JSON-RPC connection closed before a response was received.');
        $response->await();
    }

    public function testRejectsNonJsonWhitespaceAroundMessage(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer("\0{\"jsonrpc\":\"2.0\",\"method\":\"ping\"}\0\n"), $output);
        $handled = false;
        $peer->onMessage(static function () use (&$handled): void {
            $handled = true;
        });

        $peer->listen();

        $this->assertFalse($handled);
        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::PARSE_ERROR, 'message' => 'Parse error'],
        ]], $output->messages());
    }

    public function testMalformedLineYieldsParseErrorAndKeepsReading(): void
    {
        $input = new ReadableBuffer(
            'not json' . "\n"
            . '{"jsonrpc":"2.0","id":2,"method":"ping","params":{}}' . "\n"
        );
        $output = new CapturingStream();
        $peer = new JsonRpcPeer($input, $output);

        $seen = [];
        $peer->onMessage(static function (JsonRpcMessage $m) use (&$seen): void {
            $seen[] = $m->getMethod();
        });
        $peer->listen();

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => JsonRpcError::PARSE_ERROR, 'message' => 'Parse error'],
        ]], $output->messages());
        $this->assertSame(['ping'], $seen, 'A malformed line must not stop the peer from reading the next one.');
    }
}
