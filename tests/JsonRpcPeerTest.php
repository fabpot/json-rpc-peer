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
use Symfony\Component\Agent\Acp\JsonRpc\JsonRpcError;
use Symfony\Component\Agent\Acp\JsonRpc\JsonRpcMessage;
use Symfony\Component\Agent\Acp\JsonRpc\JsonRpcPeer;

final class JsonRpcPeerTest extends TestCase
{
    public function testDispatchesInboundRequestsToTheMessageHandler(): void
    {
        $input = new ReadableBuffer(
            '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"x":1}}'."\n"
            .'{"jsonrpc":"2.0","method":"note","params":{}}'."\n"
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
        $this->assertSame('ping', $received[0]->method);
        $this->assertSame(1, $received[0]->id);
        $this->assertSame(['x' => 1], $received[0]->params);
        $this->assertTrue($received[1]->isNotification());
    }

    public function testRespondsWithResultAndNotifies(): void
    {
        $output = new CapturingStream();
        $peer = new JsonRpcPeer(new ReadableBuffer(''), $output);

        $peer->respond(7, ['ok' => true]);
        $peer->notify('session/update', ['sessionId' => 's1']);

        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]],
            ['jsonrpc' => '2.0', 'method' => 'session/update', 'params' => ['sessionId' => 's1']],
        ], $output->messages());
    }

    public function testMalformedLineYieldsParseErrorAndKeepsReading(): void
    {
        $input = new ReadableBuffer(
            'not json'."\n"
            .'{"jsonrpc":"2.0","id":2,"method":"ping","params":{}}'."\n"
        );
        $output = new CapturingStream();
        $peer = new JsonRpcPeer($input, $output);

        $seen = [];
        $peer->onMessage(static function (JsonRpcMessage $m) use (&$seen): void {
            $seen[] = $m->method;
        });
        $peer->listen();

        $messages = $output->messages();
        $this->assertSame(JsonRpcError::PARSE_ERROR, $messages[0]['error']['code']);
        $this->assertSame(['ping'], $seen, 'A malformed line must not stop the peer from reading the next one.');
    }
}
