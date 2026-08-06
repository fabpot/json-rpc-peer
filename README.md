# JSON-RPC

A small, asynchronous, bidirectional [JSON-RPC 2.0](https://www.jsonrpc.org/specification)
peer built on [amphp](https://amphp.org).

Unlike an HTTP-oriented JSON-RPC client or server, a peer uses one persistent
connection where both sides can send requests and notifications. Requests run
concurrently, and responses may arrive in any order. This makes the package a
good fit for persistent protocols such as LSP and MCP.

## Installation

```bash
composer require fabpot/json-rpc-peer
```

PHP 8.4 or later is required.

## Quick start

Create a transport, peer, and dispatcher:

```php
use Amp\ByteStream;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\StreamJsonRpcTransport;

$transport = new StreamJsonRpcTransport(
    ByteStream\getStdin(),
    ByteStream\getStdout(),
);
$peer = new JsonRpcPeer($transport);
$dispatcher = new JsonRpcDispatcher($peer);
```

Register request and notification handlers:

```php
$dispatcher->onRequest('sum', function (\stdClass $params): \stdClass {
    return (object) ['total' => array_sum($params->values)];
});

$dispatcher->onNotification('log', function (\stdClass $params): void {
    fwrite(\STDERR, $params->message."\n");
});
```

Then listen for messages. `listen()` is a one-shot operation and returns when
the connection closes:

```php
$peer->listen();
```

## Transports

### Line-delimited streams

`StreamJsonRpcTransport` exchanges one complete JSON-RPC message per line. It is
suitable for protocols where messages cannot contain literal line breaks:

```php
$transport = new StreamJsonRpcTransport($input, $output);
```

Messages are limited to 16 MiB by default. Configure the limit when needed:

```php
$transport = new StreamJsonRpcTransport(
    $input,
    $output,
    maximumMessageBytes: 4 * 1024 * 1024,
);
```

### Content-Length streams

`ContentLengthJsonRpcTransport` supports the framing used by protocols such as
LSP:

```php
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;

$transport = new ContentLengthJsonRpcTransport($input, $output);
```

Headers default to an 8 KiB limit and messages to a 16 MiB limit:

```php
$transport = new ContentLengthJsonRpcTransport(
    $input,
    $output,
    maximumHeaderBytes: 8 * 1024,
    maximumMessageBytes: 4 * 1024 * 1024,
);
```

### WebSocket

Install the optional WebSocket dependency:

```bash
composer require amphp/websocket:^2
```

Then pass an Amp WebSocket client to the transport:

```php
use Fabpot\JsonRpc\WebsocketJsonRpcTransport;

$transport = new WebsocketJsonRpcTransport($websocketClient);
```

Each text message contains one JSON-RPC message. Binary messages are rejected.
The default inbound and outbound limit is 16 MiB and can be changed with the
`maximumMessageBytes` constructor argument.

## Handling requests

Handlers receive the decoded `params` value and return the response result:

```php
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcError;

$dispatcher->onRequest('divide', function (\stdClass $params): float|int {
    if (!is_int($params->value ?? null) || !is_int($params->by ?? null)) {
        throw new JsonRpcException(
            JsonRpcError::INVALID_PARAMS,
            'Expected integer "value" and "by" parameters.',
        );
    }

    if (0 === $params->by) {
        throw new JsonRpcException(JsonRpcError::INVALID_PARAMS, 'Cannot divide by zero.');
    }

    return $params->value / $params->by;
});
```

Throw `JsonRpcException` for an error that should be returned to the caller.
Unexpected exceptions become an internal-error response. Register an error
handler to log unexpected request or notification failures:

```php
use Fabpot\JsonRpc\JsonRpcMessage;

$dispatcher->onUnhandledError(
    function (\Throwable $error, JsonRpcMessage $message) use ($logger): void {
        $logger->error('JSON-RPC handler failed.', [
            'exception' => $error,
            'method' => $message->getMethod(),
        ]);
    },
);
```

### JSON values

Incoming JSON values keep their shape:

- objects become `stdClass` instances;
- arrays become PHP lists;
- omitted `params` are passed as `null`;
- explicit `[]` and `{}` remain distinguishable.

The same mapping applies to results and remote error data. A handler may narrow
its parameter type to the shape expected by its method. Requests with a different
parameter shape receive an `Invalid params` error, while mismatched notifications
are ignored.

Outbound methods accept `array|object|null` parameters. `null`, the default,
omits `params`; use `[]` for an empty JSON array and `new \stdClass()` for an
empty JSON object:

```php
$peer->notify('without-params');
$peer->notify('positional', []);
$peer->notify('named', new \stdClass());
```

Associative arrays follow PHP's `json_encode()` behavior, but `stdClass` is
recommended when an object shape must round-trip exactly.

### Accessing the current message

Request handlers may accept `Cancellation` and `JsonRpcMessage` as trailing
arguments. Use the message to access the request ID or method:

```php
use Amp\Cancellation;
use Fabpot\JsonRpc\JsonRpcMessage;

$dispatcher->onRequest(
    'inspect',
    function (
        \stdClass $params,
        Cancellation $cancellation,
        JsonRpcMessage $message,
    ): \stdClass {
        return (object) [
            'id' => $message->getId(),
            'method' => $message->getMethod(),
        ];
    },
);
```

Notification handlers may accept `JsonRpcMessage` as their second argument:

```php
$dispatcher->onNotification(
    'inspect',
    function (\stdClass $params, JsonRpcMessage $message): void {
        // ...
    },
);
```

Handlers that do not need these arguments can omit them.

### Canceling inbound work

A request handler may accept an Amp `Cancellation` as its second argument. Pass
it to cancellable Amp operations and check it during long-running work:

```php
use Amp\Cancellation;
use Amp\CancelledException;
use Fabpot\JsonRpc\Exception\JsonRpcException;

$dispatcher->onRequest(
    'run',
    function (\stdClass $params, Cancellation $cancellation): array {
        try {
            return processItems($params->items, $cancellation);
        } catch (CancelledException) {
            throw new JsonRpcException(-32800, 'Request canceled.');
        }
    },
);
```

JSON-RPC does not define a cancellation notification. Register the convention
used by your protocol before calling `listen()`:

```php
$dispatcher->onCancel('$/cancelRequest', 'id');
```

For a custom payload, handle the notification and call `cancelRequest()`
directly. It returns the number of active handlers that matched the ID:

```php
$dispatcher->onNotification(
    'custom/cancel',
    function (\stdClass $params) use ($dispatcher): void {
        $dispatcher->cancelRequest($params->requestId);
    },
);
```

Active request handlers are also canceled when the peer closes.

## Sending requests and notifications

Run the listener in a separate coroutine before awaiting an outbound request:

```php
use function Amp\async;

$listener = async($peer->listen(...));

$result = $peer->request(
    'workspace/status',
    (object) ['workspace' => '/project'],
)->await();
```

Remote JSON-RPC errors are raised as `JsonRpcException` when the future is
awaited. Responses are matched by ID and may arrive in any order.

Send a notification when no response is expected:

```php
$peer->notify('progress', (object) ['percent' => 42]);
```

### Timeouts and cancellation

Pass an Amp `Cancellation` to stop waiting for an outbound response. A
`TimeoutCancellation` provides a deadline:

```php
use Amp\TimeoutCancellation;

$result = $peer->request(
    'workspace/status',
    (object) ['workspace' => '/project'],
    new TimeoutCancellation(5),
)->await();
```

Cancellation fails the returned future and late responses are ignored. It does
not send a protocol-specific cancellation notification.

When a protocol requires such a notification, use `startRequest()` to access
the generated ID and send the notification only when the application decides to
cancel:

```php
use Fabpot\JsonRpc\Exception\JsonRpcException;

$request = $peer->startRequest('compact', $params);

if ($shouldCancel) {
    $peer->notify('$/cancelRequest', (object) ['id' => $request->getId()]);
}

try {
    $result = $request->getFuture()->await();
} catch (JsonRpcException $e) {
    // Handle the cancellation error defined by the remote protocol.
}
```

The protocol notification and local Amp cancellation are separate concerns.
Pass a `Cancellation` to `startRequest()` when the application should also stop
waiting locally.

### Batches

Use explicit request and notification entries. The returned array contains one
future for each `BatchRequest`, in request order:

```php
use Fabpot\JsonRpc\BatchNotification;
use Fabpot\JsonRpc\BatchRequest;

[$status, $configuration] = $peer->batch(
    new BatchRequest('workspace/status'),
    new BatchNotification('progress', (object) ['percent' => 42]),
    new BatchRequest('workspace/configuration'),
);

$status = $status->await();
$configuration = $configuration->await();
```

Each request entry can have its own cancellation:

```php
use Amp\TimeoutCancellation;

[$slow, $fast] = $peer->batch(
    new BatchRequest('slow', cancellation: new TimeoutCancellation(1)),
    new BatchRequest('fast'),
);
```

## Limits and shutdown

The peer accepts 64 concurrent inbound requests and 128 batch entries by
default. Configure these limits when creating it:

```php
$peer = new JsonRpcPeer(
    $transport,
    maximumConcurrentInboundRequests: 32,
    maximumBatchEntries: 64,
);
```

Requests received above the concurrency limit get a server-overloaded error.
Messages above transport limits and batches above the batch limit are rejected.

Call `close()` to stop the peer and close its transport. Outstanding outbound
requests then fail with `ConnectionClosedException`:

```php
$peer->close();
$listener->await();
```

`isClosed()` reports whether shutdown has started, so it becomes true as soon as
outbound work is rejected. `onClose()` registers a callback that runs after local
or remote shutdown completes.

## Traffic logging

Install the optional PSR logger dependency:

```bash
composer require "psr/log:^1|^2|^3"
```

Wrap a PSR logger with `PsrTrafficLogger` and pass it to the peer:

```php
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\PsrTrafficLogger;

$trafficLogger = new PsrTrafficLogger($logger, [
    'privateKey',
]);
$peer = new JsonRpcPeer($transport, $trafficLogger);
```

Common credential fields and configured keys are redacted before messages are
logged. Because logging complete payloads can expose application data, review
your logger configuration before enabling it in production.

## Extending the package

The peer, dispatcher, built-in transports, and value objects are final.
Integrate other connection types with `JsonRpcTransportInterface`. A custom
transport must yield complete messages, serialize concurrent writes, and provide
an idempotent `close()` that unblocks an active `receive()` call. The peer owns
and closes it.

Custom traffic loggers implement `TrafficLoggerInterface`. They are responsible
for appropriate redaction and must not throw because logging failures abort the
affected peer operation. Request and notification handlers are plain callables,
so application-specific dispatching can be composed around the provided
dispatcher without subclassing it.
