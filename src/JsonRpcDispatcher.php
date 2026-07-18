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

/**
 * Maps JSON-RPC method names to request and notification handlers.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcDispatcher
{
    /** @var array<string, callable(array<array-key, mixed>, RequestResponder): void> */
    private array $requestHandlers = [];

    /** @var array<string, callable(array<array-key, mixed>): void> */
    private array $notificationHandlers = [];

    public function __construct(
        private readonly JsonRpcPeer $peer,
    ) {
        $peer->onMessage($this->handle(...));
    }

    /**
     * @param callable(array<array-key, mixed>, RequestResponder): void $handler
     */
    public function onRequest(string $method, callable $handler): void
    {
        $this->requestHandlers[$method] = $handler;
    }

    /**
     * @param callable(array<array-key, mixed>): void $handler
     */
    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    public function handle(JsonRpcMessage $message): void
    {
        $method = $message->getMethod();
        $params = $message->getParams();

        if ($message->isNotification()) {
            $handler = $this->notificationHandlers[$method] ?? null;
            if (null !== $handler) {
                $handler($params);
            }

            return;
        }

        $responder = new RequestResponder($this->peer, $message->getId());
        $handler = $this->requestHandlers[$method] ?? null;
        if (null === $handler) {
            $responder->reject(JsonRpcError::METHOD_NOT_FOUND, \sprintf('Method not found: %s', $method));

            return;
        }

        try {
            $handler($params, $responder);
        } catch (JsonRpcException $e) {
            $responder->reject($e->getCode(), $e->getMessage(), $e->getData());
        } catch (\Throwable) {
            $responder->reject(JsonRpcError::INTERNAL_ERROR, 'Internal error');
        }
    }
}
