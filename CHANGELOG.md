# Changelog

## 1.0.0

- Add concise registration for protocol-specific cancellation notifications.
- Run inbound request handlers concurrently with return values and cooperative cancellation.
- Add credential redaction to the PSR-3 traffic logger.
- Fix JSON-RPC validation, batch isolation, and listener shutdown handling.
- Add continuous integration for PHP 8.4 and 8.5.
- Add live duplex communication and stream failure tests.
- Document batch futures and listener shutdown behavior.
- Mark response sender and writer implementation details as internal.
- Remove unnecessary development stability configuration.

## 0.2.0 - 2026-07-19

- Fix request and response batch classification.
- Simplify batch notification dispatch.
- Reject non-finite response results and error data.

## 0.1.0 - 2026-07-19

- Add bidirectional JSON-RPC 2.0 requests, notifications, responses, and batches over line-delimited Amp streams.
- Add deferred request handling with `JsonRpcDispatcher` and `RequestResponder`.
- Add outbound request futures with response ID matching and remote error propagation.
- Add package-specific exceptions for invalid payloads, invalid responses, stream failures, and closed connections.
- Add optional traffic logging through `TrafficLoggerInterface` and `PsrTrafficLogger`.
- Add validation for malformed requests, responses, IDs, parameters, and non-finite values.
