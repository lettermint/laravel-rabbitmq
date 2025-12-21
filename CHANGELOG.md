# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
