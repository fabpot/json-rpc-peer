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
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\RuntimeException;
use Fabpot\JsonRpc\Exception\UnexpectedValueException;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class StreamJsonRpcTransportTest extends TestCase
{
    public function testReceivesCompleteMessagesAcrossArbitraryChunks(): void
    {
        $input = new ReadableIterableStream((static function (): iterable {
            yield '{"first":';
            yield "1}\r\n{\"second\":2}\n{\"third\":";
            yield '3}';
        })());
        $transport = new StreamJsonRpcTransport($input, new CapturingStream());

        $this->assertSame('{"first":1}', $transport->receive());
        $this->assertSame('{"second":2}', $transport->receive());
        $this->assertSame('{"third":3}', $transport->receive());
        $this->assertNull($transport->receive());
    }

    public function testAcceptsMessageAtConfiguredLimitWithCrLfDelimiter(): void
    {
        $transport = new StreamJsonRpcTransport(
            new ReadableIterableStream(["{}\r\n"]),
            new CapturingStream(),
            maximumMessageBytes: 2,
        );

        $this->assertSame('{}', $transport->receive());
    }

    public function testRejectsOversizedInboundMessage(): void
    {
        $transport = new StreamJsonRpcTransport(
            new ReadableIterableStream(['abc']),
            new CapturingStream(),
            maximumMessageBytes: 2,
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('message exceeds');
        $transport->receive();
    }

    public function testCancellationInterruptsPendingReceive(): void
    {
        $pipe = new Pipe(4096);
        $cancellation = new DeferredCancellation();
        $transport = new StreamJsonRpcTransport($pipe->getSource(), new CapturingStream());
        $receive = async(fn(): ?string => $transport->receive($cancellation->getCancellation()));
        $cancellation->cancel();

        $this->expectException(CancelledException::class);
        $receive->await();
    }

    public function testSendsOneLineDelimitedMessage(): void
    {
        $output = new CapturingStream();
        $transport = new StreamJsonRpcTransport(new ReadableIterableStream([]), $output);

        $transport->send('{"jsonrpc":"2.0","method":"ping"}');

        $this->assertSame([['jsonrpc' => '2.0', 'method' => 'ping']], $output->messages());
    }

    public function testRejectsOversizedOutboundMessage(): void
    {
        $transport = new StreamJsonRpcTransport(
            new ReadableIterableStream([]),
            new CapturingStream(),
            maximumMessageBytes: 2,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message exceeds');
        $transport->send('abc');
    }

    public function testRejectsInvalidMessageLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message limit must be a positive integer');
        new StreamJsonRpcTransport(new ReadableIterableStream([]), new CapturingStream(), maximumMessageBytes: 0);
    }

    public function testCloseInterruptsPendingReceive(): void
    {
        $input = new Pipe(4096);
        $transport = new StreamJsonRpcTransport($input->getSource(), new CapturingStream());
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
        $transport = new StreamJsonRpcTransport($input, $output);

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
        $transport = new StreamJsonRpcTransport(new FailingReadStream(''), new CapturingStream());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read from the JSON-RPC connection.');
        $transport->receive();
    }
}
