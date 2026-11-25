import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, readFileSync} from 'node:fs';

// public/build is committed and deployed as-is. A merge once committed CSS containing Git
// conflict markers (and two copies of the stylesheet); this guards the published files.
const root = new URL('../../public/build/', import.meta.url);

test('every asset in the published manifest exists and is free of merge conflict markers', () => {
    const manifest = JSON.parse(readFileSync(new URL('manifest.json', root), 'utf8'));
    const files = new Set();
    for (const entry of Object.values(manifest)) {
        files.add(entry.file);
        for (const css of entry.css ?? []) files.add(css);
        for (const asset of entry.assets ?? []) files.add(asset);
    }
    assert.ok(files.size > 0);
    for (const file of files) {
        const path = new URL(file, root);
        assert.ok(existsSync(path), `${file} is listed in the manifest but missing`);
        if (!/\.(css|js|mjs)$/.test(file)) continue;
        const text = readFileSync(path, 'utf8');
        assert.doesNotMatch(text, /^(<{7}|={7}|>{7})/m, `${file} contains a merge conflict marker`);
    }
});
