import test from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'vite';

test('PDF worker emitted URL follows the serving module instead of a build-time asset host', async () => {
    const previousAssetUrl = process.env.ASSET_URL;
    process.env.ASSET_URL = 'https://assets.example.test';
    try {
        // Exercise the real production configuration without replacing public/build.
        const result = await build({logLevel: 'silent', build: {write: false}});
        const output = result.output;
        const worker = output.find(item => item.type === 'asset' && item.fileName.endsWith('.mjs'));
        const loader = output.find(item => item.type === 'chunk' && item.name === 'pdf.worker.min');

        assert.ok(worker, 'the PDF.js worker is emitted');
        assert.ok(loader, 'the shared worker URL loader is emitted');
        assert.ok(loader.code.includes(worker.fileName.split('/').at(-1)));
        assert.match(loader.code, /import\.meta\.url/);
        assert.doesNotMatch(loader.code, /https?:\/\//);
    } finally {
        if (previousAssetUrl === undefined) delete process.env.ASSET_URL;
        else process.env.ASSET_URL = previousAssetUrl;
    }
});
