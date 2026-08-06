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
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\RuntimeException;
use Fabpot\JsonRpc\Exception\UnexpectedValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class ContentLengthJsonRpcTransportTest extends TestCase
{
    public function testReceivesCompleteMessagesAcrossArbitraryChunks(): void
    {
        $input = new ReadableIterableStream((static function (): iterable {
            yield "Content-Type: application/vscode-jsonrpc; charset=utf-8\r\ncontent-len";
            yield "gth: 0002\r\n\r\n{}Content-Length: 4\r\n\r\nnu";
            yield 'll';
        })());
        $transport = new ContentLengthJsonRpcTransport($input, new CapturingStream());

        $this->assertSame('{}', $transport->receive());
        $this->assertSame('null', $transport->receive());
        $this->assertNull($transport->receive());
    }

    public function testPeerExchangesFramedJsonRpcMessages(): void
    {
        $request = '{"jsonrpc":"2.0","id":1,"method":"sum","params":[1,2,3]}';
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ContentLengthJsonRpcTransport(
            new ReadableBuffer(self::frame($request)),
            $output,
        ));
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onRequest('sum', static fn(array $params): int|float => array_sum($params));

        $peer->listen();

        $response = new ContentLengthJsonRpcTransport(
            new ReadableBuffer($output->contents()),
            new CapturingStream(),
        )->receive();
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 6],
            json_decode((string) $response, true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testCancellationInterruptsPendingReceive(): void
    {
        $pipe = new Pipe(4096);
        $cancellation = new DeferredCancellation();
        $transport = new ContentLengthJsonRpcTransport($pipe->getSource(), new CapturingStream());
        $receive = async(fn(): ?string => $transport->receive($cancellation->getCancellation()));
        $cancellation->cancel();

        $this->expectException(CancelledException::class);
        $receive->await();
    }

    public function testSendsContentLengthUsingUtf8ByteLength(): void
    {
        $output = new CapturingStream();
        $transport = new ContentLengthJsonRpcTransport(new ReadableBuffer(), $output);

        $transport->send('"é"');

        $this->assertSame("Content-Length: 4\r\n\r\n\"é\"", $output->contents());
    }

    #[DataProvider('invalidFrameProvider')]
    public function testRejectsInvalidFrames(string $frame, string $message): void
    {
        $transport = new ContentLengthJsonRpcTransport(new ReadableBuffer($frame), new CapturingStream());

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);
        $transport->receive();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFrameProvider(): iterable
    {
        yield 'missing length' => ["Content-Type: application/json\r\n\r\n{}", 'missing a Content-Length'];
        yield 'duplicate length' => ["Content-Length: 2\r\ncontent-length: 2\r\n\r\n{}", 'duplicate'];
        yield 'negative length' => ["Content-Length: -1\r\n\r\n", 'Content-Length header is invalid'];
        yield 'non-numeric length' => ["Content-Length: two\r\n\r\n{}", 'Content-Length header is invalid'];
        yield 'overflowing length' => ["Content-Length: 999999999999999999999999\r\n\r\n", 'message exceeds'];
        yield 'malformed header' => ["Content-Length: 2\r\nBroken\r\n\r\n{}", 'header is malformed'];
        yield 'invalid header name' => ["Bad Header: value\r\nContent-Length: 2\r\n\r\n{}", 'header name is invalid'];
        yield 'whitespace before header name' => [" Content-Length: 2\r\n\r\n{}", 'header name is invalid'];
        yield 'control character in additional header' => ["X-Test: value\0\r\nContent-Length: 2\r\n\r\n{}", 'header value is invalid'];
        yield 'null-padded length' => ["Content-Length: \0 2\0\r\n\r\n{}", 'header value is invalid'];
        yield 'line feed separators' => ["Content-Length: 2\n\n{}", 'headers were complete'];
        yield 'truncated headers' => ["Content-Length: 2\r\n", 'headers were complete'];
        yield 'truncated body' => ["Content-Length: 3\r\n\r\n{}", 'body was complete'];
    }

    public function testEnforcesInboundLimits(): void
    {
        $transport = new ContentLengthJsonRpcTransport(
            new ReadableBuffer("X-Long: value\r\nContent-Length: 2\r\n\r\n{}"),
            new CapturingStream(),
            maximumHeaderBytes: 8,
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('headers exceed');
        $transport->receive();
    }

    public function testRejectsOversizedInboundMessageBeforeReadingBody(): void
    {
        $transport = new ContentLengthJsonRpcTransport(
            new ReadableBuffer("Content-Length: 3\r\n\r\n{}"),
            new CapturingStream(),
            maximumMessageBytes: 2,
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('message exceeds');
        $transport->receive();
    }

    public function testRejectsOversizedOutboundMessage(): void
    {
        $transport = new ContentLengthJsonRpcTransport(
            new ReadableBuffer(),
            new CapturingStream(),
            maximumMessageBytes: 2,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message exceeds');
        $transport->send('abc');
    }

    public function testCloseInterruptsPendingReceive(): void
    {
        $input = new Pipe(4096);
        $transport = new ContentLengthJsonRpcTransport($input->getSource(), new CapturingStream());
        $receive = async($transport->receive(...));
        delay(0);

        $transport->close();

        $this->assertNull($receive->await());
    }

    public function testCloseAttemptsToCloseOutputWhenInputCloseFails(): void
    {
        $failure = new \LogicException('Input close failed.');
        $input = $this->createMock(ReadableStream::class);
        $input->expects($this->once())->method('close')->willThrowException($failure);
        $output = new CapturingStream();
        $transport = new ContentLengthJsonRpcTransport($input, $output);

        try {
            $transport->close();
            $this->fail('The input close failure was not raised.');
        } catch (\LogicException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertTrue($output->isClosed());
    }

    public function testWrapsReadFailures(): void
    {
        $transport = new ContentLengthJsonRpcTransport(new FailingReadStream(''), new CapturingStream());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read from the JSON-RPC connection.');
        $transport->receive();
    }

    public function testRejectsWritesAfterOutputCloses(): void
    {
        $output = new CapturingStream();
        $output->close();
        $transport = new ContentLengthJsonRpcTransport(new ReadableBuffer(), $output);

        $this->expectException(ConnectionClosedException::class);
        $transport->send('{}');
    }

    private static function frame(string $message): string
    {
        return 'Content-Length: ' . \strlen($message) . "\r\n\r\n" . $message;
    }
}
