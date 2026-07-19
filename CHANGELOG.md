# Changelog

## 1.0.0

- Added bidirectional JSON-RPC 2.0 requests, notifications, responses, and batches over line-delimited Amp streams.
- Added deferred request handling with `JsonRpcDispatcher` and `RequestResponder`.
- Added outbound request futures with response ID matching and remote error propagation.
- Added package-specific exceptions for invalid payloads, invalid responses, stream failures, and closed connections.
- Added optional traffic logging through `TrafficLoggerInterface` and `PsrTrafficLogger`.
- Added validation for malformed requests, responses, IDs, parameters, and non-finite values.
