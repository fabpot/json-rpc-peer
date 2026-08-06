# Changelog

## Unreleased

- Make peer shutdown deterministic and reject outbound work after shutdown begins
- Propagate response delivery failures without misclassifying them as handler errors
- Drain all active request handlers before a listener returns
- Return method-not-found errors when no message handler is registered
- Add configurable limits for concurrent inbound requests and batch entries
- Enforce configurable inbound and outbound WebSocket message limits
- Preserve JSON object, array, and omitted parameter shapes in public values
- Add outbound request cancellation and timeout cleanup
- Cover the JSON-RPC 2.0 examples and align standard error responses
- Exercise stream and content-length framing across deterministic byte partitions
- Declare and test supported optional dependency ranges and no-dev installation
- Reject object parameters that serialize to non-structured JSON values
- Pass the inbound message as optional request and notification handler context

## 0.5.0 - 2026-07-30

- Add standard closable lifecycle support to JSON-RPC peers
- Add outbound request handles that expose generated request IDs
- Return the number of active handlers matched by inbound cancellation
- Add reporting for unexpected request and notification handler errors
- Reject duplicate request and notification handler registrations
- Add message-oriented transports for line-delimited streams and WebSocket connections
- Add content-length framed stream transport support

## 0.4.0 - 2026-07-22

- Convert error responses with unencodable data to internal errors
- Wrap stream read failures in a package exception
- Keep processing inbound messages when an automatic error response is undeliverable
- Track concurrent requests sharing an ID so shutdown cancels every active handler
- Discard undeliverable responses when the connection closes mid-request
- Fix integer overflow when correlating responses with unsafe numeric IDs
- Keep the listener alive when a notification handler throws
- Cancel active request handlers when the connection closes
- Add concise registration for protocol-specific cancellation notifications
- Run inbound request handlers concurrently with return values and cooperative cancellation
- Add credential redaction to the PSR-3 traffic logger

## 0.3.0 - 2026-07-20

- Fix JSON-RPC validation, batch isolation, and listener shutdown handling
- Add continuous integration for PHP 8.4 and 8.5
- Add live duplex communication and stream failure tests
- Document batch futures and listener shutdown behavior
- Mark response sender and writer implementation details as internal
- Remove unnecessary development stability configuration

## 0.2.0 - 2026-07-19

- Fix request and response batch classification
- Simplify batch notification dispatch
- Reject non-finite response results and error data

## 0.1.0 - 2026-07-19

- Add bidirectional JSON-RPC 2.0 requests, notifications, responses, and batches over line-delimited Amp streams
- Add deferred request handling with `JsonRpcDispatcher` and `RequestResponder`
- Add outbound request futures with response ID matching and remote error propagation
- Add package-specific exceptions for invalid payloads, invalid responses, stream failures, and closed connections
- Add optional traffic logging through `TrafficLoggerInterface` and `PsrTrafficLogger`
- Add validation for malformed requests, responses, IDs, parameters, and non-finite values
