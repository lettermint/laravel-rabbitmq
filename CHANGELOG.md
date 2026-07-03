# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

* `ConsumesQueue`: opt-in `singleActiveConsumer` flag that declares the queue
  with `x-single-active-consumer`, electing one active consumer with standby
  failover. This limits concurrent consumption but does not guarantee strict
  end-to-end FIFO ordering during redelivery or failure recovery.
  Defaults to `false`; the queue-arguments table is unchanged when not set, so
  existing queues are unaffected. Compatible with quorum queues.
* `HasRoutingKey` contract: implement it on a job to publish with a per-message
  routing key (`getRoutingKey(): string`) instead of the static `#[ConsumesQueue]`
  binding key. The exchange is still resolved from the attribute. The package
  validates the key and stores it in the payload so a release or replay keeps
  the same route. Jobs without the contract are unchanged.

## 1.0.0 - 2026-01-02

### What's Changed

* Bump actions/checkout from 3 to 6 by @dependabot[bot] in https://github.com/lettermint/laravel-rabbitmq/pull/2
* Bump stefanzweifel/git-auto-commit-action from 6 to 7 by @dependabot[bot] in https://github.com/lettermint/laravel-rabbitmq/pull/1
* feat: migrate to phpamqplib for better heartbeat and reliability by @bjarn in https://github.com/lettermint/laravel-rabbitmq/pull/4

### New Contributors

* @bjarn made their first contribution in https://github.com/lettermint/laravel-rabbitmq/pull/4

**Full Changelog**: https://github.com/lettermint/laravel-rabbitmq/compare/0.2.0...1.0.0

## [Unreleased]

## [1.0.0] - 2024-12-19

### Added

- Initial open source release
- `#[ConsumesQueue]` attribute for declarative queue topology
- `#[Exchange]` attribute for exchange definitions
- Automatic Dead Letter Queue (DLQ) creation and management
- DLQ replay command (`rabbitmq:replay-dlq`)
- Delayed message support via `rabbitmq_delayed_message_exchange` plugin
- Priority queue support with `HasPriority` interface
- Quorum queue support for high availability
- Circuit breaker pattern for connection resilience
- Comprehensive Artisan commands:
  - `rabbitmq:consume` - Start a queue consumer
  - `rabbitmq:declare` - Declare topology (exchanges, queues, bindings)
  - `rabbitmq:topology` - Display topology tree
  - `rabbitmq:queues` - List queues with statistics
  - `rabbitmq:purge` - Purge messages from a queue
  - `rabbitmq:replay-dlq` - Replay dead-lettered messages
  - `rabbitmq:health` - Health check command
  
- Fallback routing for jobs without attributes (third-party packages)
- Graceful shutdown handling with SIGTERM/SIGINT
- Publisher confirms for reliable message delivery
- Kubernetes-ready deployment with HPA examples
- Prometheus metrics support (via RabbitMQ plugin)
