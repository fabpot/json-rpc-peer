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

use Amp\ByteStream\ReadableIterableStream;
use Fabpot\JsonRpc\Exception\RuntimeException;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use PHPUnit\Framework\TestCase;

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

    public function testSendsOneLineDelimitedMessage(): void
    {
        $output = new CapturingStream();
        $transport = new StreamJsonRpcTransport(new ReadableIterableStream([]), $output);

        $transport->send('{"jsonrpc":"2.0","method":"ping"}');

        $this->assertSame([['jsonrpc' => '2.0', 'method' => 'ping']], $output->messages());
    }

    public function testWrapsReadFailures(): void
    {
        $transport = new StreamJsonRpcTransport(new FailingReadStream(''), new CapturingStream());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read from the JSON-RPC connection.');
        $transport->receive();
    }
}
