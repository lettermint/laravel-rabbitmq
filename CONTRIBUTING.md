# Contributing

Use synthetic jobs and neutral queue names in tests and examples. Keep application topology, credentials, deployment files, and private incident evidence outside this package.

## Local checks

```bash
composer install
composer test
composer analyse
composer format
node --test .github/scripts/update-changelog.test.cjs
```

The PHP test process has a 512 MiB memory limit. Functional consumer tests use a separate worker threshold because all tests share one PHP process. A dedicated test verifies shutdown at the worker memory limit. These test settings do not change the runtime worker default.

To repeat a CI test order:

```bash
vendor/bin/pest --ci --order-by=random --random-order-seed=1788758977 --exclude-group=integration
```

## Broker integration tests

The integration suite requires the pinned three-node Docker Compose fixture. Each broker has a separate test volume. Missing brokers fail the suite. Check the published ports before starting the fixture; do not point fault tests at an application broker.

```bash
docker compose -f tests/Integration/docker/compose.yaml up -d --wait --wait-timeout 120
RABBITMQ_HOST=127.0.0.1 \
RABBITMQ_PORT=25672 \
RABBITMQ_MANAGEMENT_URL=http://127.0.0.1:25673 \
RABBITMQ_USER=guest \
RABBITMQ_PASSWORD=guest \
RABBITMQ_VHOST=/ \
composer test-integration
docker compose -f tests/Integration/docker/compose.yaml down --volumes
```

Remove the fixture after the tests, including when a test fails. The cleanup command deletes its test data. Fault tests verify three online queue members before leader loss, majority loss, and network partitions. They do not establish independent host or cloud-storage recovery or production capacity.

## Changelog updates

The `Update Changelog` workflow runs when a release is published. It reads all published GitHub releases, adds missing versions to `CHANGELOG.md`, and commits that file directly to the default branch. It uses release tags and publication dates. It includes published pre-releases and skips drafts.

Existing summaries and `Unreleased` entries remain unchanged. Edit an existing entry through a normal pull request when its release notes need correction. When preparing a release, move its completed `Unreleased` entries under the dated version heading. Later automation will preserve that summary.

Repeated runs do not add duplicate versions. Run the workflow manually with `workflow_dispatch` to restore missing entries after a failed run. A normal push is used; the workflow does not force-push over other changes.

The update job uses a dedicated write-enabled deploy key for this repository. The organization and enterprise policies must allow deploy keys before you can create one. Store its private key in the `CHANGELOG_DEPLOY_KEY` Actions secret and permit deploy keys to bypass the applicable branch rules. The workflow checks out the default branch with that key and limits its commit to `CHANGELOG.md`. The GitHub API token only needs read access.

A deploy key grants repository access, not access to one file or workflow. Keep it dedicated to this automation. Pull-request test jobs do not receive the key. Normal contributor changes continue through pull requests.

To add missing release entries locally without a commit or push:

```bash
gh api --paginate --slurp repos/lettermint/laravel-rabbitmq/releases \
    > /tmp/rabbitmq-releases.json
node .github/scripts/update-changelog.cjs /tmp/rabbitmq-releases.json
```

Review the resulting diff before committing it. The generator reads JSON as data; it does not run release-note text as shell commands.

## Pull requests and issues

Start a branch from `main`. Add tests for changed behavior, update the related documentation, and run the checks above. Use Conventional Commits, such as `fix: preserve retry routing`. Explain the changed behavior and test results in the pull request.

For a bug report, include PHP, Laravel, and RabbitMQ versions, steps to reproduce, expected and actual results, and relevant logs without credentials or private payloads.

Report security vulnerabilities to security@lettermint.co rather than a public issue.
