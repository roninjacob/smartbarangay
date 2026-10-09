const frames = document.querySelectorAll('[data-pdf-preview]');
if (frames.length) {
    Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')]).then(([pdfjs, worker]) => {
        pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
        frames.forEach(async (frame) => {
            const status = frame.querySelector('[data-pdf-status]');
            const controls = frame.querySelector('[data-pdf-controls]');
            const canvas = frame.querySelector('[data-pdf-canvas]');
            const previous = frame.querySelector('[data-pdf-previous]');
            const next = frame.querySelector('[data-pdf-next]');
            let pageNumber = 1;
            let busy = false;
            let resizePending = false;
            try {
                const url = new URL(frame.dataset.previewUrl, window.location.href);
                if (url.origin !== window.location.origin) throw new Error('Invalid preview origin');
                // Display pixels only: no PDF scripts, embedded links or external viewers.
                const document = await pdfjs.getDocument({ url: url.href, isEvalSupported: false, enableXfa: false, useSystemFonts: true }).promise;
                const render = async () => {
                    if (busy) return;
                    busy = true;
                    previous.disabled = next.disabled = true;
                    try {
                        const page = await document.getPage(pageNumber);
                        const base = page.getViewport({ scale: 1 });
                        const availableWidth = Math.max(1, Math.min(820, frame.querySelector('.pdf-preview-sheet').clientWidth));
                        const outputScale = Math.min(window.devicePixelRatio || 1, 2);
                        const viewport = page.getViewport({ scale: availableWidth / base.width * outputScale });
                        canvas.width = Math.ceil(viewport.width);
                        canvas.height = Math.ceil(viewport.height);
                        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                        canvas.hidden = false;
                        status.hidden = true;
                        controls.hidden = false;
                        frame.querySelector('[data-pdf-page]').textContent = `Page ${pageNumber} of ${document.numPages}`;
                        canvas.setAttribute('aria-label', `Official certificate preview, page ${pageNumber} of ${document.numPages}`);
                    } finally {
                        busy = false;
                        previous.disabled = pageNumber <= 1;
                        next.disabled = pageNumber >= document.numPages;
                        if (resizePending) {
                            resizePending = false;
                            render().catch(fail);
                        }
                    }
                };
                previous.addEventListener('click', () => { if (!busy && pageNumber > 1) { pageNumber--; render().catch(fail); } });
                next.addEventListener('click', () => { if (!busy && pageNumber < document.numPages) { pageNumber++; render().catch(fail); } });
                function fail() {
                    status.hidden = false;
                    status.textContent = 'The preview could not be displayed. Open the protected PDF preview below or review the official template.';
                    canvas.hidden = controls.hidden = true;
                }
                await render();
                if (frame.hasAttribute('data-print-document')) {
                    const printPages = window.document.createElement('div');
                    printPages.className = 'certificate-print-pages';
                    const pageStyles = window.document.createElement('style');
                    for (let number = 1; number <= document.numPages; number++) {
                        const page = await document.getPage(number);
                        const size = page.getViewport({ scale: 1 });
                        const viewport = page.getViewport({ scale: 2 });
                        const sheet = window.document.createElement('section');
                        sheet.className = 'certificate-print-sheet';
                        sheet.style.setProperty('--page-width', `${size.width}pt`);
                        sheet.style.setProperty('--page-height', `${size.height}pt`);
                        sheet.style.page = `certificate${number}`;
                        pageStyles.textContent += `@page certificate${number} { size: ${size.width}pt ${size.height}pt; margin: 0; }`;
                        const image = window.document.createElement('canvas');
                        image.width = Math.ceil(viewport.width);
                        image.height = Math.ceil(viewport.height);
                        await page.render({ canvasContext: image.getContext('2d'), viewport }).promise;
                        sheet.append(image);
                        printPages.append(sheet);
                    }
                    window.document.head.append(pageStyles);
                    frame.append(printPages);
                    const print = window.document.querySelector('[data-certificate-browser-print]');
                    print.disabled = false;
                    print.addEventListener('click', () => window.print());
                }
                let previewWidth = frame.querySelector('.pdf-preview-sheet').clientWidth;
                new ResizeObserver(([entry]) => {
                    if (entry.contentRect.width === previewWidth) return;
                    previewWidth = entry.contentRect.width;
                    if (busy) resizePending = true;
                    else render().catch(fail);
                }).observe(frame.querySelector('.pdf-preview-sheet'));
            } catch {
                status.hidden = false;
                status.textContent = 'The preview could not be displayed. Open the protected PDF preview below or review the official template.';
            }
        });
    }).catch(() => {
        frames.forEach((frame) => { frame.querySelector('[data-pdf-status]').textContent = 'Document preview is unavailable. Open the protected PDF preview below.'; });
    });
}
