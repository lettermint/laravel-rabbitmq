const assert = require('node:assert/strict');
const { test } = require('node:test');
const { mkdtempSync, readFileSync, rmSync, writeFileSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join } = require('node:path');
const { execFileSync } = require('node:child_process');
const { updateChangelog } = require('./update-changelog.cjs');

function release(tag, date, overrides = {}) {
    return {
        tag_name: tag,
        published_at: `${date}T12:00:00Z`,
        html_url: `https://github.com/example/queue-driver/releases/tag/${tag}`,
        body: '## Changes\n\n- Correct retry handling.',
        draft: false,
        prerelease: false,
        ...overrides,
    };
}

const existing = '# Changelog\n\n## Unreleased\n\n- Pending change.\n\n'
    + '## 1.0.0 - 2026-01-01\n\n- Reviewed release summary.\n';

test('the local command accepts paginated GitHub CLI output', () => {
    const directory = mkdtempSync(join(tmpdir(), 'changelog-test-'));
    try {
        const file = join(directory, 'CHANGELOG.md');
        const releases = join(directory, 'releases.json');
        writeFileSync(file, existing);
        writeFileSync(releases, JSON.stringify([[release('v1.1.0', '2026-02-01')], [release('v1.2.0', '2026-03-01')]]));
        execFileSync(process.execPath, [join(__dirname, 'update-changelog.cjs'), releases, file]);
        const result = readFileSync(file, 'utf8');
        assert.match(result, /## 1.2.0 - 2026-03-01/);
        assert.match(result, /## 1.1.0 - 2026-02-01/);
        assert.match(result, /Reviewed release summary/);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});

test('backfills missing releases in date order and keeps reviewed notes and unreleased work', () => {
    const releases = [release('0.9.0', '2025-12-01'), release('v1.2.0', '2026-03-01'),
        release('v1.0.0', '2026-01-01'), release('v1.1.0', '2026-02-01')];
    const result = updateChangelog(existing, releases);

    assert.match(result, /## Unreleased\n\n- Pending change\./);
    assert.match(result, /## 1.0.0 - 2026-01-01\n\n- Reviewed release summary\./);
    assert.deepEqual([...result.matchAll(/^## (\d+\.\d+\.\d+)/gm)].map((match) => match[1]),
        ['1.2.0', '1.1.0', '1.0.0', '0.9.0']);
    assert.match(result, /### Changes/);
    assert.equal(updateChangelog(result, releases), result);
});

test('uses the tag and publication date instead of the editable release title', () => {
    const result = updateChangelog(existing, [release('v1.1.0', '2026-02-01', { name: 'A different title' })]);
    assert.match(result, /## 1.1.0 - 2026-02-01/);
    assert.doesNotMatch(result, /A different title/);
});

test('adds a blank line after a release heading before a list', () => {
    const result = updateChangelog(existing, [release('v1.1.0', '2026-02-01', { body: '## Changes\n- A fix.' })]);
    assert.match(result, /### Changes\n\n- A fix\./);
});

test('skips drafts, marks published pre-releases, and handles empty notes', () => {
    const result = updateChangelog(existing, [
        { draft: true, tag_name: 'unfinished' },
        release('v1.1.0-rc.1', '2026-02-01', { prerelease: true, body: null }),
    ]);
    assert.doesNotMatch(result, /unfinished/);
    assert.match(result, /## 1.1.0-rc.1 - 2026-02-01 \(pre-release\)/);
    assert.match(result, /\[Release notes\]\(https:\/\/github.com\/example\/queue-driver\/releases\/tag\/v1.1.0-rc.1\)/);
});

test('keeps release text literal and does not alter headings inside code blocks', () => {
    const body = '# 9.9.9\r\n\r\n```sh\r\n## 8.8.8\r\necho "$(example)"\r\n```\r\n\r\n## More';
    const result = updateChangelog(existing, [release('v1.1.0', '2026-02-01', { body })]);
    assert.match(result, /### 9.9.9/);
    assert.match(result, /```sh\n## 8.8.8\necho "\$\(example\)"\n```/);
    assert.match(result, /### More/);
    assert.equal(updateChangelog(result, [release('v1.1.0', '2026-02-01', { body })]), result);
});

test('recognizes linked version headings and older two-part tags', () => {
    const text = '# Changelog\n\n## [v0.1] - 2025-12-01\n\n- First release.\n';
    assert.equal(updateChangelog(text, [release('0.1', '2025-12-01')]), text);
});

test('can add the first release without an existing version section', () => {
    const result = updateChangelog('# Changelog\n', [release('v1.0.0', '2026-01-01')]);
    assert.match(result, /^# Changelog\n\n## 1.0.0 - 2026-01-01/);
});

test('rejects duplicate versions and invalid published release data', () => {
    assert.throws(() => updateChangelog(existing, {}), /list/);
    assert.throws(() => updateChangelog(existing, [release('bad tag', '2026-02-01')]), /invalid/);
    assert.throws(() => updateChangelog(existing, [release('v1.1.0', 'bad date')]), /invalid/);
    assert.throws(() => updateChangelog(existing, [release('1.1.0', '2026-02-01', { html_url: 'javascript:example' })]), /invalid/);
    assert.throws(() => updateChangelog(existing, [release('1.1.0', '2026-02-01'), release('v1.1.0', '2026-02-01')]), /Duplicate/);
    assert.throws(() => updateChangelog(existing + '\n## v1.0.0 - 2026-01-01\n', []), /Duplicate/);
});
