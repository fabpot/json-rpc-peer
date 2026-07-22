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

```mermaid
sequenceDiagram
    participant A as Application A
    participant PA as JsonRpcPeer A
    participant PB as JsonRpcPeer B
    participant B as Application B

    Note over PA,PB: One persistent duplex connection

    par Application A calls Application B
        A->>PA: request("sum", params)
        PA->>PB: Request, id 1
        PB->>B: onRequest("sum")
        B-->>PB: return result
        PB-->>PA: Response, id 1
        PA-->>A: Future resolves
    and Application B calls Application A
        B->>PB: request("status", params)
        PB->>PA: Request, id 7
        PA->>A: onRequest("status")
        A-->>PA: return result
        PA-->>PB: Response, id 7
        PB-->>B: Future resolves
    end

    B->>PB: notify("progress", params)
    PB->>PA: Notification
    PA->>A: onNotification("progress")

    Note over PA,PB: Requests can overlap and responses can arrive out of order
```

## Spec conformance

The peer implements JSON-RPC 2.0, including mixed request and notification
batches.

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

$input = ByteStream\getStdin();
$output = ByteStream\getStdout();

$peer = new JsonRpcPeer($input, $output);
$dispatcher = new JsonRpcDispatcher($peer);
```

### Running the peer

After registering the handlers described below, call `listen()`. It reads and
dispatches messages until the input stream reaches EOF or is closed, then waits
for active request handlers to finish before returning:

```php
$peer->listen();
```

Malformed lines receive a JSON-RPC `PARSE_ERROR` response and are skipped, so a
single bad line does not stop the listener.

### Handling requests and notifications

Register handlers by method name. A request handler returns its result; the
dispatcher sends it as the JSON-RPC response.

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

Unexpected exceptions receive an `INTERNAL_ERROR` response without exposing
their message. Requests for methods without a registered handler receive a
`METHOD_NOT_FOUND` response.

### Long-running requests and cancellation

Each request handler runs in its own coroutine, so it may use suspending Amp
APIs without blocking the peer. The dispatcher creates an Amp `Cancellation`
for every inbound request and passes it as the optional second argument. A
handler that supports cancellation passes it to Amp APIs or checks it between
units of work:

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

JSON-RPC does not define a cancellation notification. Before calling
`listen()`, register the convention used by your protocol as a normal
notification. When the remote peer sends this notification while a request is
running, call `cancelRequest()` with the ID of that inbound request:

```php
$dispatcher->onNotification('cancel', function (array $params) use ($dispatcher): void {
    $dispatcher->cancelRequest($params['requestId']);
});
```

For example, while the handler for this inbound request is running:

```json
{"jsonrpc":"2.0","id":42,"method":"run","params":{"items":[]}}
```

The remote peer can then send this notification. The registered notification
handler calls `cancelRequest(42)`:

```json
{"jsonrpc":"2.0","method":"cancel","params":{"requestId":42}}
```

The Language Server Protocol uses a different method and parameter name:

```php
$dispatcher->onNotification('$/cancelRequest', function (array $params) use ($dispatcher): void {
    $dispatcher->cancelRequest($params['id']);
});
```

```mermaid
sequenceDiagram
    participant Remote as Remote peer
    participant Peer as JsonRpcPeer
    participant Dispatcher as JsonRpcDispatcher
    participant Handler as Request handler

    Remote->>Peer: Request, id 42
    Peer->>Dispatcher: Dispatch request
    Dispatcher->>Handler: Handle with Cancellation
    Handler-->>Handler: Suspend on cancellable work
    Remote->>Peer: Cancellation notification, id 42
    Peer->>Dispatcher: Dispatch notification
    Dispatcher->>Dispatcher: cancelRequest(42)
    Dispatcher-->>Handler: Cancellation requested
    Handler-->>Dispatcher: Throw protocol-specific JsonRpcException
    Dispatcher-->>Peer: Error response, id 42
    Peer-->>Remote: Error response, id 42
```

Cancellation is cooperative. The handler must reach a cancellation check or a
cancellable suspension before it stops. JSON-RPC also does not define the
response to a canceled request, so the handler chooses the result or error. In
the example above, `-32000` is an application-defined error code.

### Emitting requests and notifications

Outbound requests return an Amp `Future`. Responses are matched by ID, so they
can arrive in any order. Remote JSON-RPC errors throw a `JsonRpcException` when
the future is awaited.

`listen()` must process the response while the request is pending, so run it in
a separate coroutine. Await the listener when shutting down to ensure it has
stopped:

```php
$listener = \Amp\async($peer->listen(...));

$result = $peer->request('workspace/status', [
    'workspace' => '/project',
])->await();

$input->close();
$listener->await();
```

Calling `$input->close()` is the local way to stop `listen()`; an EOF caused by
the remote side closing its output stops it as well. When the input closes, all
outstanding outbound requests fail with a
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

The response to an inbound batch is emitted once every request has settled. Its
entries may be ordered by settlement rather than input order, as allowed by the
specification.

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
