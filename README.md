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
use Fabpot\JsonRpc\RequestResponder;

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
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\RequestResponder;

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

Close the input stream to stop `listen()`. Closing the input also fails every
outstanding request with a
`Fabpot\JsonRpc\Exception\ConnectionClosedException`. New requests throw the
same exception after the listener stops.

The peer can also push notifications to the other side at any time:

```php
$peer->notify('progress', ['percent' => 42]);
```

For protocols that use JSON-RPC batches, pass explicit request and notification
entries. The returned array contains futures for request entries only, in the
same order as those requests:

```php
use Fabpot\JsonRpc\BatchNotification;
use Fabpot\JsonRpc\BatchRequest;

[$status, $configuration] = $peer->batch(
    new BatchRequest('workspace/status'),
    new BatchNotification('progress', ['percent' => 42]),
    new BatchRequest('workspace/configuration'),
);

$status = $status->await();
$configuration = $configuration->await();
```

### Running the loop

`listen()` reads inbound lines until the stream closes, dispatching each
message. Malformed lines are answered with a JSON-RPC parse error and skipped,
so a single bad line never tears down the connection.

```php
$peer->listen();
```

## Spec conformance

The peer implements JSON-RPC 2.0, including mixed request and notification
batches. Batch responses are emitted once every request has settled and may be
ordered by settlement rather than input order, as allowed by the specification.

## Traffic logging

Pass a `TrafficLoggerInterface` to the peer to record raw inbound and outbound
lines. `PsrTrafficLogger` forwards them to a PSR-3 logger at the `debug` level
and recursively redacts common credential keys and credentials embedded in
URLs:

```php
use Fabpot\JsonRpc\PsrTrafficLogger;

$peer = new JsonRpcPeer($input, $output, new PsrTrafficLogger($logger));
```

Pass additional protocol-specific sensitive keys as the second argument when needed.
Install `psr/log` to use this optional adapter.
