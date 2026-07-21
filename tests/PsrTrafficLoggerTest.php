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
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class PsrTrafficLoggerTest extends TestCase
{
    public function testLogsRedactedInboundAndOutboundMessages(): void
    {
        $logger = $this->createLogger();
        $trafficLogger = new PsrTrafficLogger($logger, ['authorization', 'customSecret']);

        $trafficLogger->logInbound('{"authorization":"Bearer token","nested":{"customSecret":"secret","url":"https://user:pass@example.com/path"}}');
        $trafficLogger->logOutbound('{"result":"pong"}');

        $this->assertSame([
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', [
                'direction' => 'inbound',
                'message' => '{"authorization":"[redacted]","nested":{"customSecret":"[redacted]","url":"https://[redacted]@example.com/path"}}',
            ]],
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', ['direction' => 'outbound', 'message' => '{"result":"pong"}']],
        ], $logger->records);
    }

    public function testDoesNotLogPayloadWhenRedactionFails(): void
    {
        $logger = $this->createLogger();
        $trafficLogger = new PsrTrafficLogger($logger);

        $trafficLogger->logInbound('{"password":"secret","value":1e400}');
        $trafficLogger->logOutbound('{"password":"secret"');

        $this->assertSame([
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', ['direction' => 'inbound', 'message' => '[redaction failed]']],
            [LogLevel::DEBUG, 'JSON-RPC {direction}: {message}', ['direction' => 'outbound', 'message' => '[redaction failed]']],
        ], $logger->records);
    }

    /**
     * @return AbstractLogger&object{records: list<array{mixed, string, array<string, mixed>}>}
     */
    private function createLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<string, mixed>}> */
            public array $records = [];

            /**
             * @param mixed                $level
             * @param mixed                $message
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                if (!\is_string($message) && !$message instanceof \Stringable) {
                    throw new \InvalidArgumentException('Expected a string or Stringable message.');
                }

                $this->records[] = [$level, (string) $message, $context];
            }
        };
    }
}
