import { test } from 'node:test';
import assert from 'node:assert/strict';
import { officialPdfBytes, saveDocumentLayout } from '../../resources/js/document-builder-requests.js';

async function withFetch(response, callback) {
    const original = globalThis.fetch;
    const calls = [];
    globalThis.fetch = async (...args) => { calls.push(args); return response; };
    try { await callback(calls); } finally { globalThis.fetch = original; }
}
const base = 'https://example.test/admin/services/1/document-layout';
test('official PDF bytes use private same-origin delivery with bounded body', async () => {
    await withFetch(new Response('%PDF-1.4\nsynthetic', { headers: { 'Content-Type': 'application/pdf' } }), async calls => {
        const bytes = await officialPdfBytes('/private/pdf', base);
        assert.equal(new TextDecoder().decode(bytes).slice(0, 5), '%PDF-');
        assert.equal(calls[0][1].credentials, 'same-origin');
        assert.equal(calls[0][1].cache, 'no-store');
        assert.equal(calls[0][1].headers.Accept, 'application/pdf');
    });
});
test('cross-origin PDF address is rejected before sending a request', async () => {
    await withFetch(null, async calls => {
        await assert.rejects(officialPdfBytes('https://other.test/pdf', base), /does not match/);
        assert.equal(calls.length, 0);
    });
});
test('HTML authentication page and followed redirects never reach PDF.js', async () => {
    const html = new Response('<html>private server details</html>', { headers: { 'Content-Type': 'text/html' } });
    await withFetch(html, () => assert.rejects(officialPdfBytes('/private/pdf', base), /returned a page/));
    const redirected = new Response('%PDF-1.4', { headers: { 'Content-Type': 'application/pdf' } });
    Object.defineProperty(redirected, 'redirected', { value: true });
    await withFetch(redirected, () => assert.rejects(officialPdfBytes('/private/pdf', base), /session or document access/));
});
test('missing PDF, denied access and server errors have safe specific feedback', async () => {
    for (const [status, message] of [[404, /missing or its configuration/], [403, /session or document access/], [500, /server could not deliver/]]) {
        await withFetch(new Response('private exception trace', { status }), () => assert.rejects(officialPdfBytes('/private/pdf', base), message));
    }
});
test('malformed and oversized payloads are rejected even without Content-Length', async () => {
    await withFetch(new Response('not a PDF', { headers: { 'Content-Type': 'application/pdf' } }), () => assert.rejects(officialPdfBytes('/pdf', base), /not a readable PDF/));
    const bytes = new Uint8Array(10 * 1024 * 1024 + 1);
    await withFetch(new Response(bytes, { headers: { 'Content-Type': 'application/pdf' } }), () => assert.rejects(officialPdfBytes('/pdf', base), /10 MB/));
});
test('layout only succeeds with JSON and a matching next configuration version', async () => {
    const payload = { version: 2, placements: [] };
    await withFetch(Response.json({ version: 3 }), async calls => {
        assert.equal((await saveDocumentLayout('/save', payload, 'synthetic-csrf')).version, 3);
        assert.equal(calls[0][1].headers['X-CSRF-TOKEN'], 'synthetic-csrf');
        assert.deepEqual(JSON.parse(calls[0][1].body), payload);
    });
    await withFetch(Response.json({}), () => assert.rejects(saveDocumentLayout('/save', payload, 'synthetic'), /confirmation was incomplete/));
    await withFetch(new Response('<html>private trace</html>'), () => assert.rejects(saveDocumentLayout('/save', payload, 'synthetic'), /layout could not be saved/));
});
test('layout validation remains visible while expired session and network failures stay safe', async () => {
    await withFetch(Response.json({ errors: { layout: ['Fields must not overlap.'] } }, { status: 422 }), () => assert.rejects(saveDocumentLayout('/save', { version: 1 }, 'synthetic'), /Fields must not overlap/));
    await withFetch(new Response('', { status: 419 }), () => assert.rejects(saveDocumentLayout('/save', { version: 1 }, 'synthetic'), /Sign in again/));
    const original = globalThis.fetch;
    globalThis.fetch = async () => { throw new Error('credential-bearing transport error'); };
    try { await assert.rejects(saveDocumentLayout('/save', { version: 1 }, 'synthetic'), /Reopen the editor/); }
    finally { globalThis.fetch = original; }
});
