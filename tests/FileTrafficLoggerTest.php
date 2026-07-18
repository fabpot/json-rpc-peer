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
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if (null === $this->directory) {
            return;
        }

        @unlink($this->directory . '/nested/traffic.log');
        @rmdir($this->directory . '/nested');
        @rmdir($this->directory);
    }

    public function testCreatesDirectoryAndRedactsSecrets(): void
    {
        $this->directory = sys_get_temp_dir() . '/json-rpc-peer-' . bin2hex(random_bytes(8));
        $path = $this->directory . '/nested/traffic.log';
        $logger = new FileTrafficLogger($path);

        $logger->logInbound('{"authorization":"Bearer secret","nested":{"apiKey":"key","url":"https://user:password@example.com/path"}}');
        $logger->logOutbound('{"result":true}');

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString(' >> {"authorization":"[redacted]","nested":{"apiKey":"[redacted]","url":"https://[redacted]@example.com/path"}}', $contents);
        $this->assertStringContainsString(' << {"result":true}', $contents);
        $this->assertStringNotContainsString('Bearer secret', $contents);
        $this->assertStringNotContainsString('user:password', $contents);
    }
}
