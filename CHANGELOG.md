# Changelog

This file records notable changes. The project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## 1.1.0 - 2026-08-22

### Added

- Added a configurable driver name and a physical topology prefix.
- Added an explicit topology registry and strict unknown-queue checks.
- Added validated dynamic routing with `HasRoutingKey`.
- Added mandatory publishing, publisher confirmations, returned-message checks, negative-confirmation checks, and confirmation timeouts.
- Added durable classic TTL queues for delayed jobs and released jobs.
- Added bounded connection recovery and channel rebuilds.
- Added optional multiple-queue consumption and single-active-consumer topology.
- Added `rabbitmq:audit`, `rabbitmq:probe`, and safe broker round-trip diagnostics.
- Added publish, release, retry, dead-letter, replay, probe, and recovery events.
- Added structured queue lifecycle logs.
- Added an optional Filament 5 page for RabbitMQ dead-letter inspection, retry, forget, and bulk actions.
- Added Laravel 13, PHP 8.5, Pest 4, and RabbitMQ 4.2 test coverage.

### Changed

- Main queues and dead-letter queues use durable quorum queues by default.
- Quorum queues use `reject-publish` and at-least-once dead lettering.
- The consumer delegates job execution to Laravel `Worker`.
- Laravel attempts are separate from RabbitMQ broker delivery count.
- Release and replay keep message properties, payload, headers, and routing data.
- Replay publishes and confirms a replacement before it acknowledges the source dead-letter message.
- Health checks perform a real broker operation.
- Attribute discovery runs only in console processes.
- Documentation now states the at-least-once delivery model and monitoring limits.

### Removed

- Removed the dependency on the archived RabbitMQ delayed-message plug-in.
- Removed the default TTL from final dead-letter messages.
- Removed unsupported Prometheus, atomic batch, strict FIFO, and exactly-once implications from the documentation.

## 1.0.0 - 2026-01-02

### Added

- Added the first stable package release.
- Added the Laravel RabbitMQ connector and queue implementation.
- Added attribute-based topology, dead-letter commands, priority support, quorum queues, a circuit breaker, and worker commands.
