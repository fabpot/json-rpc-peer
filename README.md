# JSON-RPC

A minimal, asynchronous, bidirectional [JSON-RPC 2.0](https://www.jsonrpc.org/specification)
peer over line-delimited JSON streams, built on [amphp](https://amphp.org) byte
streams and the [Revolt](https://revolt.run) event loop.

Unlike the HTTP-shaped JSON-RPC libraries common in PHP, this is a **peer**: a
single long-lived connection over which both sides send requests and
notifications, answer inbound requests, and resolve responses out of order. It
is the primitive behind stdio protocols such as the
[Language Server Protocol](https://microsoft.github.io/language-server-protocol/)
and the [Model Context Protocol](https://modelcontextprotocol.io).

## Why this exists

The PHP ecosystem has plenty of JSON-RPC libraries, but they model one HTTP
request mapping to one response, are strictly a server or a client, run
synchronously, and cannot let the endpoint initiate a call back to the other
side. Bidirectional stdio protocols need none of that shape and all of what is
missing:

- **Peer, not server-or-client**: the same endpoint serves inbound requests and
  emits its own requests and notifications.
- **Persistent duplex transport**: line-delimited JSON over any amphp
  `ReadableStream`/`WritableStream` (stdio, a socket, or in-memory streams for
  tests), not one-shot HTTP.
- **Deferred responses**: a request handler may hold its responder and resolve
  it later from another coroutine, so a long-running call can answer while the
  reader keeps processing inbound messages (for example an interrupt) on the
  same connection.

## Installation

```bash
composer require fabpot/json-rpc-peer
```

## Usage

### Wiring a peer

```php
use Amp\ByteStream;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;

$peer = new JsonRpcPeer(
    ByteStream\getStdin(),
    ByteStream\getStdout(),
);

$dispatcher = new JsonRpcDispatcher($peer);
```

### Handling requests and notifications

Register handlers by method name. A request handler receives the params and a
`RequestResponder` it must resolve or reject. A notification handler receives
only the params and never produces a response.

```php
$dispatcher->onRequest('sum', function (array $params, RequestResponder $responder): void {
    $responder->resolve(['total' => array_sum($params['values'])]);
});

$dispatcher->onNotification('log', function (array $params): void {
    fwrite(\STDERR, $params['message']."\n");
});
```

Throwing a `JsonRpcException` from a request handler is turned into a JSON-RPC
error response:

```php
$dispatcher->onRequest('divide', function (array $params, RequestResponder $responder): void {
    if (0 === $params['by']) {
        throw new JsonRpcException(JsonRpcError::INVALID_PARAMS, 'Cannot divide by zero.');
    }

    $responder->resolve($params['value'] / $params['by']);
});
```

### Deferred responses

A handler may keep its responder and resolve it later. The reader keeps
dispatching inbound messages in the meantime, so a cancellation can arrive and
settle the same request while its work is still running.

```php
$dispatcher->onRequest('run', function (array $params, RequestResponder $responder) use (&$active): void {
    $active = $responder;

    \Amp\async(function () use ($responder): void {
        $result = doLongRunningWork();
        $responder->resolve($result);
    });
});

$dispatcher->onNotification('cancel', function () use (&$active): void {
    $active?->resolve(['stopReason' => 'cancelled']);
});
```

A responder settles at most once: a late `resolve()`/`reject()` after a cancel
is silently ignored.

### Emitting requests and notifications

Outbound requests return an Amp `Future`. Responses are matched by ID, so they
can arrive in any order. Remote JSON-RPC errors throw a `JsonRpcException` when
the future is awaited. The listener must be running in another coroutine while
a request is pending.

```php
$listener = \Amp\async($peer->listen(...));
$result = $peer->request('workspace/status', ['workspace' => '/project'])->await();
```

The peer can also push notifications to the other side at any time:

```php
$peer->notify('progress', ['percent' => 42]);
```

### Running the loop

`listen()` reads inbound lines until the stream closes, dispatching each
message. Malformed lines are answered with a JSON-RPC parse error and skipped,
so a single bad line never tears down the connection.

```php
$peer->listen();
```

## Traffic logging

Pass a `TrafficLoggerInterface` to the peer to record raw inbound and outbound
lines, for example to debug a broken interaction after the fact.
Implementations are responsible for redacting secrets before persisting a line.

```php
$peer = new JsonRpcPeer($input, $output, $trafficLogger);
```

## Testing

The peer takes any amphp streams, so it drives cleanly against in-memory byte
streams with no real stdio. Feed scripted client messages on the readable side
and assert the emitted responses and notifications on the writable side.

## Components

| Class | Responsibility |
| --- | --- |
| `JsonRpcPeer` | Reads and writes line-delimited JSON-RPC messages over amphp streams. |
| `JsonRpcDispatcher` | Routes inbound methods to request and notification handlers. |
| `RequestResponder` | Resolves or rejects a single inbound request, now or later. |
| `JsonRpcMessage` | A validated inbound request or notification. |
| `JsonRpcError` | The reserved JSON-RPC 2.0 error codes. |
| `JsonRpcException` | Thrown by a handler to produce an error response. |
| `TrafficLoggerInterface` | Optional hook to record raw traffic. |

## License

Released under the [MIT license](LICENSE).
