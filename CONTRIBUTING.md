# Contributing to Laravel RabbitMQ

Thank you for considering contributing to Laravel RabbitMQ! We welcome contributions from the community.

## Development Setup

1. **Clone the repository**:
   ```bash
   git clone https://github.com/lettermint/laravel-rabbitmq.git
   cd laravel-rabbitmq
   ```

2. **Install dependencies**:
   ```bash
   composer install
   ```

3. **Start RabbitMQ** (for integration tests):
   ```bash
   docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
   ```

## Running Tests

```bash
# Run all tests
vendor/bin/pest

# Run specific test file
vendor/bin/pest tests/Unit/AttributeScannerTest.php

# Run with coverage
vendor/bin/pest --coverage
```

## Code Style

This project follows Laravel coding standards. Run the formatter before committing:

```bash
vendor/bin/pint
```

## Static Analysis

We use PHPStan for static analysis. Ensure your changes pass:

```bash
vendor/bin/phpstan analyse
```

## Pull Request Process

1. **Fork** the repository and create your branch from `main`.

2. **Write tests** for any new functionality or bug fixes.

3. **Update documentation** if you're changing behavior or adding features.

4. **Run the test suite** and ensure all tests pass.

5. **Run code formatting** with Pint.

6. **Submit a pull request** with a clear description of your changes.

## Commit Messages

We follow conventional commit messages:

- `feat:` New features
- `fix:` Bug fixes
- `docs:` Documentation changes
- `refactor:` Code refactoring
- `test:` Test additions or changes
- `chore:` Maintenance tasks

Example:
```
feat: add support for custom exchange arguments

Add ability to pass arbitrary arguments to exchange declarations
via the Exchange attribute.
```

## Reporting Issues

When reporting issues, please include:

1. Laravel and PHP versions
2. RabbitMQ version
3. Steps to reproduce
4. Expected vs actual behavior
5. Relevant logs or error messages

## Security Vulnerabilities

If you discover a security vulnerability, please email security@lettermint.co instead of using the issue tracker. All security vulnerabilities will be promptly addressed.

## Questions?

Feel free to open an issue for questions about contributing or reach out to the maintainers.
