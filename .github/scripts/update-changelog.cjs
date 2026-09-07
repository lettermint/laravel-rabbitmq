const { readFileSync, writeFileSync } = require('node:fs');

const versionPattern = 'v?\\d+\\.\\d+(?:\\.\\d+)?(?:-[0-9A-Za-z.-]+)?(?:\\+[0-9A-Za-z.-]+)?';
const versionKey = (tag) => tag.replace(/^v/, '');

function outsideCodeBlocks(text, transform) {
    let fence = null;
    let offset = 0;

    return text.split('\n').map((line) => {
        const start = offset;
        offset += line.length + 1;
        const marker = line.match(/^ {0,3}(`{3,}|~{3,})/);

        if (marker) {
            if (fence === null) {
                fence = marker[1];
            } else if (marker[1][0] === fence[0] && marker[1].length >= fence.length) {
                fence = null;
            }
        }

        return fence === null ? transform(line, start) : line;
    }).join('\n');
}

function nestHeadings(body) {
    const text = body.replace(/\r\n/g, '\n').trim();
    return outsideCodeBlocks(text, (line, offset) => {
        const heading = line.replace(/^(#{1,5}) /, (_, hashes) => `${'#'.repeat(Math.max(3, hashes.length + 1))} `);
        const next = text[offset + line.length + 1];
        return /^#{3,6} /.test(heading) && next && next !== '\n' ? `${heading}\n` : heading;
    });
}

function updateChangelog(changelog, releases) {
    if (!Array.isArray(releases)) {
        throw new TypeError('Expected a list of GitHub releases.');
    }

    const byVersion = new Map();
    for (const release of releases.filter((release) => !release.draft)) {
        if (!new RegExp(`^${versionPattern}$`).test(release.tag_name)
            || !Number.isFinite(Date.parse(release.published_at))
            || !/^https:\/\/github\.com\/[^/]+\/[^/]+\/releases\/tag\/[^\s]+$/.test(release.html_url)) {
            throw new Error('A published release has an invalid tag, date, or URL.');
        }

        const key = versionKey(release.tag_name);
        if (byVersion.has(key)) {
            throw new Error(`Duplicate release version: ${key}`);
        }
        byVersion.set(key, release);
    }

    const text = changelog.replace(/\r\n/g, '\n');
    const headings = [];
    const headingPattern = new RegExp(`^## \\[?(${versionPattern})\\]?(?:[^\\n]*)$`);
    outsideCodeBlocks(text, (line, offset) => {
        const match = line.match(headingPattern);
        if (match) {
            headings.push(Object.assign(match, { index: offset }));
        }
        return line;
    });
    const intro = text.slice(0, headings[0]?.index ?? text.length).trimEnd();
    const sections = new Map();

    headings.forEach((heading, index) => {
        const key = versionKey(heading[1]);
        if (sections.has(key)) {
            throw new Error(`Duplicate changelog version: ${key}`);
        }
        const section = text.slice(heading.index, headings[index + 1]?.index ?? text.length).trim();
        const date = byVersion.get(key)?.published_at ?? heading[0].match(/\d{4}-\d{2}-\d{2}/)?.[0];
        if (!date || !Number.isFinite(Date.parse(date))) {
            throw new Error(`No release date for changelog version: ${key}`);
        }
        sections.set(key, { text: section, date });
    });

    let added = false;
    for (const [key, release] of byVersion) {
        if (sections.has(key)) {
            continue;
        }
        const notes = nestHeadings(release.body ?? '');
        const label = release.prerelease ? ' (pre-release)' : '';
        sections.set(key, {
            text: `## ${key} - ${release.published_at.slice(0, 10)}${label}\n\n`
                + (notes ? `${notes}\n\n` : '')
                + `[Release notes](${release.html_url})`,
            date: release.published_at,
        });
        added = true;
    }

    if (!added) {
        return changelog;
    }

    const ordered = [...sections.values()].sort((a, b) => Date.parse(b.date) - Date.parse(a.date));
    return `${intro}\n\n${ordered.map((section) => section.text).join('\n\n')}\n`;
}

module.exports = { updateChangelog };

if (require.main === module) {
    const [releasesFile, changelogFile = 'CHANGELOG.md'] = process.argv.slice(2);
    if (!releasesFile) {
        throw new Error('Usage: node update-changelog.cjs releases.json [CHANGELOG.md]');
    }
    const releases = JSON.parse(readFileSync(releasesFile, 'utf8')).flat();
    writeFileSync(changelogFile, updateChangelog(readFileSync(changelogFile, 'utf8'), releases));
}
