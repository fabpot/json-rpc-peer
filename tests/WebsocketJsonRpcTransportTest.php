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

use Amp\ByteStream\BufferException;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketMessage;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\UnexpectedValueException;
use Fabpot\JsonRpc\WebsocketJsonRpcTransport;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class WebsocketJsonRpcTransportTest extends TestCase
{
    public function testRejectsInvalidMessageLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message limit must be a positive integer');
        new WebsocketJsonRpcTransport($this->createStub(WebsocketClient::class), maximumMessageBytes: 0);
    }

    public function testAcceptsInboundMessageAtConfiguredLimit(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('receive')->willReturn(WebsocketMessage::fromText('{}'));
        $transport = new WebsocketJsonRpcTransport($client, maximumMessageBytes: 2);

        $this->assertSame('{}', $transport->receive());
    }

    public function testRejectsBufferedInboundMessageAboveConfiguredLimit(): void
    {
        $message = WebsocketMessage::fromText('{}');
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('receive')->willReturn($message);
        $transport = new WebsocketJsonRpcTransport($client, maximumMessageBytes: 1);

        try {
            $transport->receive();
            $this->fail('The oversized WebSocket message was not rejected.');
        } catch (UnexpectedValueException $e) {
            $this->assertSame('The JSON-RPC message exceeds the configured limit.', $e->getMessage());
        }

        $this->assertTrue($message->isClosed());
    }

    public function testRejectsStreamedInboundMessageAboveConfiguredLimit(): void
    {
        $message = WebsocketMessage::fromText(new ReadableIterableStream(['{', '}']));
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('receive')->willReturn($message);
        $transport = new WebsocketJsonRpcTransport($client, maximumMessageBytes: 1);

        try {
            $transport->receive();
            $this->fail('The oversized WebSocket message was not rejected.');
        } catch (UnexpectedValueException $e) {
            $this->assertSame('The JSON-RPC message exceeds the configured limit.', $e->getMessage());
            $this->assertInstanceOf(BufferException::class, $e->getPrevious());
        }

        $this->assertTrue($message->isClosed());
    }

    public function testReceivesOneTextMessage(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('receive')->willReturn(WebsocketMessage::fromText('{"jsonrpc":"2.0","method":"ping"}'));
        $transport = new WebsocketJsonRpcTransport($client);

        $this->assertSame('{"jsonrpc":"2.0","method":"ping"}', $transport->receive());
    }

    public function testCancellationInterruptsPendingReceive(): void
    {
        $cancellation = new DeferredCancellation();
        $client = $this->createStub(WebsocketClient::class);
        $client->method('receive')->willReturnCallback(static function (?Cancellation $cancellation): never {
            delay(10, cancellation: $cancellation);

            throw new \LogicException('The receive was not canceled.');
        });
        $transport = new WebsocketJsonRpcTransport($client);
        $receive = async(fn(): ?string => $transport->receive($cancellation->getCancellation()));
        $cancellation->cancel();

        $this->expectException(CancelledException::class);
        $receive->await();
    }

    public function testSendsOneTextMessageWithoutStreamFraming(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('sendText')->with('{"jsonrpc":"2.0","method":"ping"}');
        $transport = new WebsocketJsonRpcTransport($client);

        $transport->send('{"jsonrpc":"2.0","method":"ping"}');
    }

    public function testRejectsBinaryMessages(): void
    {
        $client = $this->createStub(WebsocketClient::class);
        $client->method('receive')->willReturn(WebsocketMessage::fromBinary('{}'));
        $transport = new WebsocketJsonRpcTransport($client);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Binary WebSocket messages cannot contain JSON-RPC payloads.');
        $transport->receive();
    }

    public function testAcceptsOutboundMessageAtConfiguredLimit(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('sendText')->with('{}');
        $transport = new WebsocketJsonRpcTransport($client, maximumMessageBytes: 2);

        $transport->send('{}');
    }

    public function testRejectsOutboundMessageAboveConfiguredLimit(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->never())->method('sendText');
        $transport = new WebsocketJsonRpcTransport($client, maximumMessageBytes: 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The JSON-RPC message exceeds the configured limit.');
        $transport->send('{}');
    }

    public function testClosesTheWebsocketClient(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('close');
        $transport = new WebsocketJsonRpcTransport($client);

        $transport->close();
    }
}
