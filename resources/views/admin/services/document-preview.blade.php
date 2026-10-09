<section class="document-preview mb-3" aria-label="Preview Certificate">
    <h3 class="h5">Preview Certificate</h3>
    @if($preview['kind'] === 'pdf')
        <div class="document-preview-frame" data-pdf-preview data-preview-url="{{ $preview['url'] }}" @if($printDocument ?? false) data-print-document @endif>
            <p class="app-note p-3" data-pdf-status role="status">Loading document preview…</p>
            <div class="pdf-preview-controls p-2 d-flex flex-wrap gap-2 align-items-center" hidden data-pdf-controls><button class="btn btn-sm btn-outline-secondary" type="button" data-pdf-previous aria-label="Previous preview page">Previous</button><span data-pdf-page class="app-note"></span><button class="btn btn-sm btn-outline-secondary" type="button" data-pdf-next aria-label="Next preview page">Next</button></div>
            <div class="pdf-preview-sheet"><canvas data-pdf-canvas role="img" aria-label="Official certificate PDF preview" hidden></canvas></div>
            <noscript><p class="app-note">Open the protected PDF preview below to view the full document.</p></noscript>
        </div>
        <p class="app-note mt-2 mb-0">If the preview is unavailable in your browser, <a href="{{ $preview['url'] }}" target="_blank" rel="noopener">open the protected PDF preview</a>.</p>
    @else
        <p class="app-note mb-0">Document preview is unavailable. Review or upload the Official Certificate PDF in Edit Service.</p>
    @endif
</section>
