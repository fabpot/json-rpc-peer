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
use Fabpot\JsonRpc\Exception\PayloadEncodingException;
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
    /** @var array<string, array{0: \Closure, 1: int, 2: \ReflectionParameter|null}> */
    private array $requestHandlers = [];

    /** @var array<string, array{0: \Closure, 1: int, 2: \ReflectionParameter|null}> */
    private array $notificationHandlers = [];

    /** @var array<string, array<int, DeferredCancellation>> */
    private array $activeRequests = [];

    /** @var (callable(\Throwable, JsonRpcMessage): void)|null */
    private $unhandledErrorHandler;

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
     * @template TParams of array<array-key, mixed>|\stdClass|null
     *
     * @param callable(TParams): mixed|callable(TParams, Cancellation): mixed|callable(TParams, Cancellation, JsonRpcMessage): mixed $handler
     */
    public function onRequest(string $method, callable $handler): void
    {
        if (isset($this->requestHandlers[$method])) {
            throw new InvalidArgumentException(sprintf('A request handler is already registered for method "%s".', $method));
        }

        $this->requestHandlers[$method] = $this->prepareHandler($handler, [Cancellation::class, JsonRpcMessage::class]);
    }

    /**
     * @template TParams of array<array-key, mixed>|\stdClass|null
     *
     * @param callable(TParams): void|callable(TParams, JsonRpcMessage): void $handler
     */
    public function onNotification(string $method, callable $handler): void
    {
        if (isset($this->notificationHandlers[$method])) {
            throw new InvalidArgumentException(sprintf('A notification handler is already registered for method "%s".', $method));
        }

        $this->notificationHandlers[$method] = $this->prepareHandler($handler, [JsonRpcMessage::class]);
    }

    /**
     * @param callable(\Throwable, JsonRpcMessage): void $handler
     */
    public function onUnhandledError(callable $handler): void
    {
        $this->unhandledErrorHandler = $handler;
    }

    public function onCancel(string $method, string $idParameter): void
    {
        $this->onNotification($method, function (array|\stdClass|null $params) use ($idParameter): void {
            if (\is_object($params)) {
                if (!property_exists($params, $idParameter)) {
                    return;
                }
                $id = $params->{$idParameter};
            } elseif (\is_array($params) && \array_key_exists($idParameter, $params)) {
                $id = $params[$idParameter];
            } else {
                return;
            }
            if (!JsonRpcValues::isValidId($id)) {
                return;
            }
            /** @var int|float|string|null $id */

            $this->cancelRequest($id);
        });
    }

    public function cancelRequest(int|float|string|null $id): int
    {
        $requests = $this->activeRequests[$this->requestKey($id)] ?? [];
        foreach ($requests as $request) {
            $request->cancel();
        }

        return \count($requests);
    }

    /**
     * @internal
     *
     * @return Future<mixed>|null
     */
    public function handle(JsonRpcMessage $message, ?RequestResponder $responder = null): ?Future
    {
        $method = $message->getMethod();
        $params = $message->getParams();

        if ($message->isNotification()) {
            $registeredHandler = $this->notificationHandlers[$method] ?? null;
            if (null !== $registeredHandler) {
                try {
                    [$handler, $argumentCount, $paramsParameter] = $registeredHandler;
                    if (!self::parameterAcceptsValue($paramsParameter, $params)) {
                        return null;
                    }
                    $handler(...\array_slice([$params, $message], 0, $argumentCount));
                } catch (\Throwable $e) {
                    $this->reportUnhandledError($e, $message);
                }
            }

            return null;
        }

        $responder ??= new RequestResponder($this->peer, $message->getId());
        $registeredHandler = $this->requestHandlers[$method] ?? null;
        if (null === $registeredHandler) {
            $responder->reject(JsonRpcError::METHOD_NOT_FOUND, 'Method not found');

            return null;
        }

        [$handler, $argumentCount, $paramsParameter] = $registeredHandler;
        if (!self::parameterAcceptsValue($paramsParameter, $params)) {
            $responder->reject(JsonRpcError::INVALID_PARAMS, 'Invalid params');

            return null;
        }

        $key = $this->requestKey($message->getId());
        $deferredCancellation = new DeferredCancellation();
        $this->activeRequests[$key][spl_object_id($deferredCancellation)] = $deferredCancellation;

        return async(function () use ($handler, $argumentCount, $message, $params, $responder, $key, $deferredCancellation): void {
            try {
                try {
                    $result = $handler(...\array_slice([
                        $params,
                        $deferredCancellation->getCancellation(),
                        $message,
                    ], 0, $argumentCount));
                } catch (JsonRpcException $e) {
                    try {
                        $responder->reject($e->getCode(), $e->getMessage(), $e->getData());
                    } catch (PayloadEncodingException $encodingError) {
                        $this->reportUnhandledError($encodingError, $message);
                        $responder->reject(JsonRpcError::INTERNAL_ERROR, 'Internal error');
                    }

                    return;
                } catch (\Throwable $e) {
                    $this->reportUnhandledError($e, $message);
                    $responder->reject(JsonRpcError::INTERNAL_ERROR, 'Internal error');

                    return;
                }

                try {
                    $responder->resolve($result);
                } catch (PayloadEncodingException $encodingError) {
                    $this->reportUnhandledError($encodingError, $message);
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

    /**
     * @param list<class-string> $contextTypes
     *
     * @return array{0: \Closure, 1: int, 2: \ReflectionParameter|null}
     */
    private function prepareHandler(callable $handler, array $contextTypes): array
    {
        $handler = $handler instanceof \Closure ? $handler : \Closure::fromCallable($handler);
        $parameters = new \ReflectionFunction($handler)->getParameters();
        if (!$parameters) {
            return [$handler, 0, null];
        }

        $argumentCount = 1;
        $lastParameter = $parameters[array_key_last($parameters)];
        foreach ($contextTypes as $index => $contextType) {
            $parameterIndex = $index + 1;
            $parameter = $parameters[$parameterIndex] ?? ($lastParameter->isVariadic() ? $lastParameter : null);
            if (null === $parameter || !self::parameterAcceptsObject($parameter, $contextType)) {
                break;
            }

            ++$argumentCount;
        }

        return [$handler, $argumentCount, $parameters[0]];
    }

    private static function parameterAcceptsValue(?\ReflectionParameter $parameter, mixed $value): bool
    {
        if (null === $parameter || null === $parameter->getType()) {
            return true;
        }

        return self::typeAcceptsValue($parameter->getType(), $value);
    }

    private static function typeAcceptsValue(\ReflectionType $type, mixed $value): bool
    {
        if (null === $value) {
            return $type->allowsNull();
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $nestedType) {
                if (self::typeAcceptsValue($nestedType, $value)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                if (!self::typeAcceptsValue($nestedType, $value)) {
                    return false;
                }
            }

            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        if (!$type->isBuiltin()) {
            return \is_object($value) && is_a($value, $type->getName());
        }

        return match ($type->getName()) {
            'array' => \is_array($value),
            'callable' => \is_callable($value),
            'iterable' => is_iterable($value),
            'mixed' => true,
            'object' => \is_object($value),
            default => false,
        };
    }

    /** @param class-string $class */
    private static function parameterAcceptsObject(\ReflectionParameter $parameter, string $class): bool
    {
        $type = $parameter->getType();
        if (null === $type) {
            return true;
        }

        return self::typeAcceptsObject($type, $class);
    }

    /** @param class-string $class */
    private static function typeAcceptsObject(\ReflectionType $type, string $class): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $nestedType) {
                if (self::typeAcceptsObject($nestedType, $class)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                if (!self::typeAcceptsObject($nestedType, $class)) {
                    return false;
                }
            }

            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        if ($type->isBuiltin()) {
            return \in_array($type->getName(), ['mixed', 'object'], true);
        }

        return is_a($class, $type->getName(), true);
    }

    private function reportUnhandledError(\Throwable $error, JsonRpcMessage $message): void
    {
        if (null === $this->unhandledErrorHandler) {
            return;
        }

        try {
            ($this->unhandledErrorHandler)($error, $message);
        } catch (\Throwable) {
        }
    }

    private function requestKey(int|float|string|null $id): string
    {
        return JsonRpcValues::requestKey($id);
    }
}
