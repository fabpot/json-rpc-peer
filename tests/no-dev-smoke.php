<?php

/*
 * This file is part of the fabpot/json-rpc-peer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Amp\Cancellation;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\JsonRpcTransportInterface;
use Fabpot\JsonRpc\PsrTrafficLogger;
use Fabpot\JsonRpc\WebsocketJsonRpcTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

if (interface_exists(Psr\Log\LoggerInterface::class) || interface_exists(Amp\Websocket\WebsocketClient::class)) {
    throw new RuntimeException('Optional dependencies must not be installed.');
}

$transport = new class implements JsonRpcTransportInterface {
    /** @var list<string> */
    public array $messages = [];

    public function receive(?Cancellation $cancellation = null): ?string
    {
        return null;
    }

    public function send(string $message): void
    {
        $this->messages[] = $message;
    }

    public function close(): void {}
};
$peer = new JsonRpcPeer($transport);
$peer->notify('ping');
if (['{"jsonrpc":"2.0","method":"ping"}'] !== $transport->messages) {
    throw new RuntimeException('The core peer smoke test failed.');
}
if (!class_exists(PsrTrafficLogger::class) || !class_exists(WebsocketJsonRpcTransport::class)) {
    throw new RuntimeException('Optional adapter classes must remain autoloadable.');
}
