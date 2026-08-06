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

use Fabpot\JsonRpc\PsrTrafficLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class PsrTrafficLoggerTest extends TestCase
{
    public function testLogsRedactedInboundAndOutboundMessages(): void
    {
        $records = [];
        $logger = $this->createLogger($records);
        $trafficLogger = new PsrTrafficLogger($logger, ['authorization', 'customSecret']);

        $trafficLogger->logInbound('{"authorization":"Bearer token","nested":{"customSecret":"secret","password":"password","url":"https://user:pass@example.com/path"}}');
        $trafficLogger->logOutbound('{"result":"pong"}');

        $this->assertSame([
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', [
                'direction' => 'inbound',
                'message' => '{"authorization":"[redacted]","nested":{"customSecret":"[redacted]","password":"[redacted]","url":"https://[redacted]@example.com/path"}}',
            ]],
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', ['direction' => 'outbound', 'message' => '{"result":"pong"}']],
        ], $records);
    }

    public function testDoesNotLogPayloadWhenRedactionFails(): void
    {
        $records = [];
        $logger = $this->createLogger($records);
        $trafficLogger = new PsrTrafficLogger($logger);

        $trafficLogger->logInbound('{"password":"secret","value":1e400}');
        $trafficLogger->logOutbound('{"password":"secret"');

        $this->assertSame([
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', ['direction' => 'inbound', 'message' => '[redaction failed]']],
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', ['direction' => 'outbound', 'message' => '[redaction failed]']],
        ], $records);
    }

    /**
     * @param list<array{mixed, string, array<string, mixed>}> $records
     * @param-out list<array{mixed, string, array<string, mixed>}> $records
     */
    private function createLogger(array &$records): LoggerInterface
    {
        $records = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(static function (mixed $message, array $context = []) use (&$records): void {
            if (!\is_string($message) && !$message instanceof \Stringable) {
                throw new \InvalidArgumentException('Expected a string or Stringable message.');
            }

            $records[] = [LogLevel::DEBUG, (string) $message, $context];
        });

        return $logger;
    }

}
