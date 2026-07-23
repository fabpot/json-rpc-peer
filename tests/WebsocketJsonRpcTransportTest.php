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

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketMessage;
use Fabpot\JsonRpc\Exception\UnexpectedValueException;
use Fabpot\JsonRpc\WebsocketJsonRpcTransport;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class WebsocketJsonRpcTransportTest extends TestCase
{
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

    public function testClosesTheWebsocketClient(): void
    {
        $client = $this->createMock(WebsocketClient::class);
        $client->expects($this->once())->method('close');
        $transport = new WebsocketJsonRpcTransport($client);

        $transport->close();
    }
}
