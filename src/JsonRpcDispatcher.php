<?php

/*
 * This file is part of the Symfony\Component package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Agent\Acp\JsonRpc;

/**
 * Maps JSON-RPC method names to request and notification handlers.
 *
 * Request handlers receive the params and a {@see RequestResponder} they must
 * resolve or reject, either inline (synchronous methods) or later from a
 * coroutine (long-running methods). Throwing a {@see JsonRpcException} from a
 * request handler is turned into a JSON-RPC error response. Notification
 * handlers return nothing and never produce a response.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcDispatcher
{
    /** @var array<string, callable(array<string, mixed>, RequestResponder): void> */
    private array $requestHandlers = [];

    /** @var array<string, callable(array<string, mixed>): void> */
    private array $notificationHandlers = [];

    public function __construct(
        private readonly JsonRpcPeer $peer,
    ) {
        $peer->onMessage($this->handle(...));
    }

    /**
     * @param callable(array<string, mixed>, RequestResponder): void $handler
     */
    public function onRequest(string $method, callable $handler): void
    {
        $this->requestHandlers[$method] = $handler;
    }

    /**
     * @param callable(array<string, mixed>): void $handler
     */
    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    public function handle(JsonRpcMessage $message): void
    {
        if (null === $message->method) {
            // A response or a message with no method: nothing to dispatch.
            return;
        }

        if ($message->isNotification()) {
            $handler = $this->notificationHandlers[$message->method] ?? null;
            if (null !== $handler) {
                $handler($message->params);
            }

            return;
        }

        $id = $message->id;
        \assert(null !== $id);
        $responder = new RequestResponder($this->peer, $id);

        $handler = $this->requestHandlers[$message->method] ?? null;
        if (null === $handler) {
            $responder->reject(JsonRpcError::METHOD_NOT_FOUND, \sprintf('Method not found: %s', $message->method));

            return;
        }

        try {
            $handler($message->params, $responder);
        } catch (JsonRpcException $e) {
            $responder->reject($e->getCode(), $e->getMessage(), $e->getData());
        }
    }
}
