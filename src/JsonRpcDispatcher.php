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

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\Exception\InvalidArgumentException;
use Fabpot\JsonRpc\Exception\JsonRpcException;

use function Amp\async;

/**
 * Maps JSON-RPC method names to request and notification handlers.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcDispatcher
{
    /** @var array<string, callable(array<array-key, mixed>): mixed|callable(array<array-key, mixed>, Cancellation): mixed> */
    private array $requestHandlers = [];

    /** @var array<string, callable(array<array-key, mixed>): void> */
    private array $notificationHandlers = [];

    /** @var array<string, array<int, DeferredCancellation>> */
    private array $activeRequests = [];

    public function __construct(
        private readonly JsonRpcPeer $peer,
    ) {
        $peer->onMessage($this->handle(...));
        $peer->getConnectionCancellation()->subscribe(function (): void {
            foreach ($this->activeRequests as $requests) {
                foreach ($requests as $request) {
                    $request->cancel();
                }
            }
        });
    }

    /**
     * @param callable(array<array-key, mixed>): mixed|callable(array<array-key, mixed>, Cancellation): mixed $handler
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

    public function onCancel(string $method, string $idParameter): void
    {
        $this->onNotification($method, function (array $params) use ($idParameter): void {
            if (!\array_key_exists($idParameter, $params)) {
                return;
            }

            $id = $params[$idParameter];
            if (!\is_int($id) && !\is_string($id) && null !== $id && (!\is_float($id) || !JsonRpcValues::isSafeFloatId($id))) {
                return;
            }

            $this->cancelRequest($id);
        });
    }

    public function cancelRequest(int|float|string|null $id): void
    {
        foreach ($this->activeRequests[$this->requestKey($id)] ?? [] as $request) {
            $request->cancel();
        }
    }

    /**
     * @return Future<mixed>|null
     */
    public function handle(JsonRpcMessage $message, ?RequestResponder $responder = null): ?Future
    {
        $method = $message->getMethod();
        $params = $message->getParams();

        if ($message->isNotification()) {
            $handler = $this->notificationHandlers[$method] ?? null;
            if (null !== $handler) {
                $handler($params);
            }

            return null;
        }

        $responder ??= new RequestResponder($this->peer, $message->getId());
        $handler = $this->requestHandlers[$method] ?? null;
        if (null === $handler) {
            $responder->reject(JsonRpcError::METHOD_NOT_FOUND, \sprintf('Method not found: %s', $method));

            return null;
        }

        $key = $this->requestKey($message->getId());
        $deferredCancellation = new DeferredCancellation();
        $this->activeRequests[$key][spl_object_id($deferredCancellation)] = $deferredCancellation;

        return async(function () use ($handler, $params, $responder, $key, $deferredCancellation): void {
            try {
                try {
                    $responder->resolve($handler($params, $deferredCancellation->getCancellation()));
                } catch (JsonRpcException $e) {
                    try {
                        $responder->reject($e->getCode(), $e->getMessage(), $e->getData());
                    } catch (InvalidArgumentException) {
                        $responder->reject(JsonRpcError::INTERNAL_ERROR, 'Internal error');
                    }
                } catch (\Throwable) {
                    $responder->reject(JsonRpcError::INTERNAL_ERROR, 'Internal error');
                }
            } catch (ConnectionClosedException) {
                // the response is undeliverable, the remote peer is gone
            } finally {
                unset($this->activeRequests[$key][spl_object_id($deferredCancellation)]);
                if (!($this->activeRequests[$key] ?? [])) {
                    unset($this->activeRequests[$key]);
                }
            }
        });
    }

    private function requestKey(int|float|string|null $id): string
    {
        return JsonRpcValues::requestKey($id);
    }
}
