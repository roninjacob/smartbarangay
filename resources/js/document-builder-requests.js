export class DocumentBuilderError extends Error {}

function responseError(response, action) {
    if (response.redirected || [401, 403, 419].includes(response.status)) {
        return new DocumentBuilderError('Your session or document access is unavailable. Sign in again, then reopen the document configuration.');
    }
    if (response.status === 404) {
        return new DocumentBuilderError('The official PDF is missing or its configuration has changed. Review the current document configuration.');
    }
    if (response.status === 413) return new DocumentBuilderError('The server rejected the request size. Ask the administrator to review the upload limits.');
    return new DocumentBuilderError(action === 'load'
        ? 'The server could not deliver the official PDF. Ask the administrator to check private storage and server logs.'
        : 'The layout could not be saved. Your unsaved changes remain on this page.');
}

export async function officialPdfBytes(value, base = location.href) {
    const url = new URL(value, base);
    if (url.origin !== new URL(base).origin) throw new DocumentBuilderError('The PDF address does not match this website. Ask the administrator to review the application URL.');
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
        const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/pdf' }, signal: controller.signal });
        if (!response.ok || response.redirected) throw responseError(response, 'load');
        if (!response.headers.get('content-type')?.toLowerCase().startsWith('application/pdf')) {
            throw new DocumentBuilderError('The server returned a page instead of the official PDF. Sign in again or ask the administrator to check the PDF delivery endpoint.');
        }
        const maximum = 10 * 1024 * 1024;
        if (Number(response.headers.get('content-length')) > maximum) throw new DocumentBuilderError('The official PDF exceeds the 10 MB limit.');
        const reader = response.body.getReader();
        const chunks = [];
        let length = 0;
        try {
            for (;;) {
                const { done, value: chunk } = await reader.read();
                if (done) break;
                length += chunk.byteLength;
                if (length > maximum) {
                    await reader.cancel();
                    throw new DocumentBuilderError('The official PDF exceeds the 10 MB limit.');
                }
                chunks.push(chunk);
            }
        } finally { reader.releaseLock(); }
        const bytes = new Uint8Array(length);
        let offset = 0;
        for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
        if (new TextDecoder().decode(bytes.subarray(0, 5)) !== '%PDF-') {
            throw new DocumentBuilderError('The delivered file is not a readable PDF. Ask the administrator to inspect the stored official file.');
        }
        return bytes;
    } catch (error) {
        if (error instanceof DocumentBuilderError) throw error;
        throw new DocumentBuilderError('The PDF request was interrupted or timed out. Check your connection and reload the editor.');
    } finally { clearTimeout(timeout); }
}

export async function saveDocumentLayout(url, payload, csrf) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
        const response = await fetch(url, { method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload), signal: controller.signal });
        if (response.redirected || [401, 403, 419].includes(response.status)) throw responseError(response, 'save');
        if (!response.headers.get('content-type')?.toLowerCase().startsWith('application/json')) throw responseError(response, 'save');
        const result = await response.json();
        if (!response.ok) {
            const messages = response.status === 422 ? Object.values(result.errors ?? {}).flat().filter(value => typeof value === 'string') : [];
            throw messages.length ? new DocumentBuilderError(messages.join(' ').slice(0, 1200)) : responseError(response, 'save');
        }
        if (!Number.isSafeInteger(result.version) || result.version !== payload.version + 1) {
            throw new DocumentBuilderError('The save confirmation was incomplete. Reopen the editor to check the stored layout before retrying.');
        }
        return result;
    } catch (error) {
        if (error instanceof DocumentBuilderError) throw error;
        throw new DocumentBuilderError('The save response was interrupted. Reopen the editor to check whether the layout was saved before retrying.');
    } finally { clearTimeout(timeout); }
}
