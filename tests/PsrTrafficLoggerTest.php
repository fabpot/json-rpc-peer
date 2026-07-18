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
    public function testLogsInboundAndOutboundMessages(): void
    {
        $logger = new class extends AbstractLogger {
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
        $trafficLogger = new PsrTrafficLogger($logger);

        $trafficLogger->logInbound('{"method":"ping"}');
        $trafficLogger->logOutbound('{"result":"pong"}');

        $this->assertSame([
            [LogLevel::DEBUG, 'JSON-RPC message.', ['direction' => 'inbound', 'message' => '{"method":"ping"}']],
            [LogLevel::DEBUG, 'JSON-RPC message.', ['direction' => 'outbound', 'message' => '{"result":"pong"}']],
        ], $logger->records);
    }
}
