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

use Fabpot\JsonRpc\FileTrafficLogger;
use PHPUnit\Framework\TestCase;

final class FileTrafficLoggerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/json-rpc-traffic-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/traffic.log');
        @rmdir($this->directory);
    }

    public function testAppendsRedactedTraffic(): void
    {
        $logger = new FileTrafficLogger($this->directory . '/traffic.log', ['authorization', 'customSecret']);

        $logger->logInbound('{"authorization":"Bearer token","nested":{"customSecret":"secret","url":"https://user:pass@example.com/path"}}');
        $logger->logOutbound('{"result":"ok"}');

        $lines = file($this->directory . '/traffic.log', \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            $this->fail('Unable to read traffic log.');
        }
        $this->assertCount(2, $lines);
        $this->assertMatchesRegularExpression('/^\S+ >> /', $lines[0]);
        $this->assertStringContainsString('"authorization":"[redacted]"', $lines[0]);
        $this->assertStringContainsString('"customSecret":"[redacted]"', $lines[0]);
        $this->assertStringContainsString('https://[redacted]@example.com/path', $lines[0]);
        $this->assertMatchesRegularExpression('/^\S+ << {"result":"ok"}$/', $lines[1]);
    }

    public function testPreservesMalformedTraffic(): void
    {
        $logger = new FileTrafficLogger($this->directory . '/traffic.log');

        $logger->logInbound('{not json}');

        $this->assertStringEndsWith(' >> {not json}', trim((string) file_get_contents($this->directory . '/traffic.log')));
    }
}
