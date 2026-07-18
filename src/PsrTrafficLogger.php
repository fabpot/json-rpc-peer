<?php

/*
 * This file is part of the fabpot/json-rpc-peer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fabpot\JsonRpc;

use Psr\Log\LoggerInterface;

final class PsrTrafficLogger implements TrafficLoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function logInbound(string $line): void
    {
        $this->logger->debug('JSON-RPC message.', [
            'direction' => 'inbound',
            'message' => $line,
        ]);
    }

    public function logOutbound(string $line): void
    {
        $this->logger->debug('JSON-RPC message.', [
            'direction' => 'outbound',
            'message' => $line,
        ]);
    }
}
