# JSON-RPC

A minimal, asynchronous, bidirectional [JSON-RPC 2.0](https://www.jsonrpc.org/specification)
peer over line-delimited JSON streams, built on [amphp](https://amphp.org) byte
streams and the [Revolt](https://revolt.run) event loop.

This is a **peer** JSON-RPC library: a single long-lived connection over which
both sides send requests and notifications, answer inbound requests, and
resolve responses out of order. It is the primitive behind stdio protocols such
as the [Language Server
Protocol](https://microsoft.github.io/language-server-protocol/) and the [Model
Context Protocol](https://modelcontextprotocol.io).

## Why this exists

The PHP ecosystem has plenty of JSON-RPC libraries, but they model one HTTP
request mapping to one response, are strictly a server or a client, run
synchronously, and cannot let the endpoint initiate a call back to the other
side. Bidirectional stdio protocols need none of that shape and all of what is
missing:

- **Peer**: the same endpoint serves inbound requests and emits its own
  requests and notifications.
- **Persistent duplex transport**: line-delimited JSON over any amphp
  `ReadableStream`/`WritableStream` (stdio, a socket, or in-memory streams for
  tests).
- **Concurrent requests**: each inbound request runs in its own coroutine, so
  handlers can suspend on asynchronous work while the peer keeps processing
  other messages on the same connection.

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

Register handlers by method name. A request handler returns its result; the
dispatcher sends it as the JSON-RPC response. Each request handler runs in its
own coroutine, so it may use suspending Amp APIs without blocking the peer.

```php
$dispatcher->onRequest('sum', function (array $params): array {
    return ['total' => array_sum($params['values'])];
});
```

A notification handler returns nothing because notifications have no response:

```php
$dispatcher->onNotification('log', function (array $params): void {
    fwrite(\STDERR, $params['message']."\n");
});
```

### Error responses

Throw a `JsonRpcException` to return a JSON-RPC error:

```php
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcError;

$dispatcher->onRequest('divide', function (array $params): float|int {
    if (0 === $params['by']) {
        throw new JsonRpcException(JsonRpcError::INVALID_PARAMS, 'Cannot divide by zero.');
    }

    return $params['value'] / $params['by'];
});
```

Unexpected exceptions are converted to an internal error without exposing their
message. Requests for methods without a registered handler receive a
`METHOD_NOT_FOUND` error.

### Long-running requests and cancellation

The dispatcher creates an Amp `Cancellation` for every inbound request and
passes it as the optional second argument. A handler that supports cancellation
passes it to Amp APIs or checks it between units of work:

```php
use Amp\Cancellation;
use function Amp\delay;

function processItems(array $items, Cancellation $cancellation): array
{
    $results = [];

    foreach ($items as $item) {
        $cancellation->throwIfRequested();
        delay(0.1, cancellation: $cancellation);
        $results[] = processItem($item);
    }

    return $results;
}
```

Request handlers do not need to create a `Future` or call `Amp\async()`; the
dispatcher already runs them in a coroutine:

```php
use Amp\Cancellation;
use Amp\CancelledException;
use Fabpot\JsonRpc\Exception\JsonRpcException;

$dispatcher->onRequest('run', function (array $params, Cancellation $cancellation): array {
    try {
        return processItems($params['items'], $cancellation);
    } catch (CancelledException) {
        throw new JsonRpcException(-32000, 'Request canceled.');
    }
});
```

JSON-RPC does not define a cancellation notification. Register the convention
used by your protocol as a normal notification and pass the target request ID
to `cancelRequest()`:

```php
$dispatcher->onNotification('cancel', function (array $params) use ($dispatcher): void {
    $dispatcher->cancelRequest($params['requestId']);
});
```

For example, the Language Server Protocol uses a different method and parameter
name:

```php
$dispatcher->onNotification('$/cancelRequest', function (array $params) use ($dispatcher): void {
    $dispatcher->cancelRequest($params['id']);
});
```

Cancellation is cooperative. The handler must reach a cancellation check or a
cancellable suspension before it stops. JSON-RPC also does not define the
response to a canceled request, so the handler chooses the result or error. In
the example above, `-32000` is an application-defined error code.

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
and recursively redacts common credential keys and credentials in values that
are URLs:

```php
use Fabpot\JsonRpc\PsrTrafficLogger;

$peer = new JsonRpcPeer($input, $output, new PsrTrafficLogger($logger));
```

Pass additional protocol-specific sensitive keys as the second argument when needed.
Redaction is intentionally conservative and does not inspect arbitrary text for
embedded credentials. Install `psr/log` to use this optional adapter.
