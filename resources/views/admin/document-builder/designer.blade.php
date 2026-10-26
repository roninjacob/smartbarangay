@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.services.document-configuration.index', $service) }}" class="service-back-link">&larr; Back to document configuration</a>
@if(!$available)
    <div class="alert alert-warning" role="alert">The official PDF has changed or is unavailable. This configuration keeps its original reference. Review the document configuration before designing its layout.</div>
@else
<section class="dashboard-panel builder-panel" aria-labelledby="designer-heading" data-document-designer>
    <div class="dashboard-panel-heading"><div class="min-w-0"><h2 id="designer-heading">{{ $service->name }}</h2><p class="panel-description">Place fields directly on the document. The original PDF stays unchanged.</p></div></div>
    <div class="builder-body">
        <script type="application/json" data-designer-data>{!! json_encode($designer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <div class="designer-toolbar">
            <div class="designer-pagination" aria-label="Document pages"><button type="button" class="btn btn-outline-secondary" data-designer-previous disabled>Previous Page</button><span data-designer-page>Loading document…</span><button type="button" class="btn btn-outline-secondary" data-designer-next disabled>Next Page</button></div>
            <button type="button" class="btn btn-primary" data-designer-save disabled>Save Layout</button>
        </div>
        <details class="designer-help"><summary>How to place and adjust fields</summary><p class="app-note">Select a field, then tap the document to place it. Drag a placed field to move it; drag its corner to resize. You can also use the move and size buttons below.</p></details>
        <div class="designer-status" role="status" aria-live="polite" data-designer-status>Loading official PDF…</div>
        <div class="designer-grid">
            <div class="designer-paper-area">
                <div class="designer-sheet" data-designer-sheet hidden>
                    <canvas data-designer-canvas role="img" aria-label="Official document page"></canvas>
                    <div class="designer-overlay" data-designer-overlay role="group" aria-label="Document field placements" tabindex="0"></div>
                </div>
            </div>
            <aside class="designer-toolbox" aria-label="Field toolbox">
                <h3>Fields</h3><p class="app-note">Choose a field to place on the current page.</p>
                @foreach(['system' => 'System Fields', 'custom' => 'Custom Fields'] as $source => $heading)
                    <h4>{{ $heading }}</h4>
                    <div class="designer-field-list">
                        @foreach($designer['fields']->where('source', $source) as $field)
                            <button type="button" class="btn btn-outline-primary designer-tool" data-designer-field="{{ $field['key'] }}" draggable="true">{{ $field['label'] }}</button>
                        @endforeach
                    </div>
                @endforeach
                <a class="btn btn-link px-0" href="{{ route('admin.services.document-fields.create', $service) }}">Add Custom Field</a>
                <label class="designer-sample"><input type="checkbox" class="form-check-input" data-designer-sample> Preview with Sample Data</label>
                <p class="app-note">Illustrative examples only. This does not create a certificate or use Resident records.</p>
                <fieldset class="designer-settings" data-designer-settings disabled>
                    <legend class="h6" data-designer-selected>Select a placed field</legend>
                    <label for="designer-font" class="form-label">Font Size</label><input id="designer-font" type="number" class="form-control mb-3" min="4" max="72" step="0.5" value="12" data-designer-font>
                    <label for="designer-align" class="form-label">Alignment</label><select id="designer-align" class="form-select mb-3" data-designer-align><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
                    <label for="designer-behavior" class="form-label">Text Behavior</label><select id="designer-behavior" class="form-select mb-3" data-designer-behavior><option value="auto_resize">Auto Resize</option><option value="wrap">Wrap</option></select>
                    <p class="form-label mb-2">Move Field</p><div class="designer-adjust" aria-label="Move selected field">
                        @foreach(['left' => '← Left', 'up' => '↑ Up', 'down' => '↓ Down', 'right' => '→ Right'] as $direction => $label)<button type="button" class="btn btn-outline-secondary" data-designer-move="{{ $direction }}">{{ $label }}</button>@endforeach
                    </div>
                    <p class="form-label mt-3 mb-2">Field Size</p><div class="designer-adjust" aria-label="Resize selected field">
                        @foreach(['narrower' => 'Narrower', 'wider' => 'Wider', 'shorter' => 'Shorter', 'taller' => 'Taller'] as $direction => $label)<button type="button" class="btn btn-outline-secondary" data-designer-size="{{ $direction }}">{{ $label }}</button>@endforeach
                    </div>
                    <button type="button" class="btn btn-outline-danger w-100 mt-3" data-designer-remove>Remove from Layout</button>
                </fieldset>
                <h4 class="mt-4">Placed on this page</h4><div data-designer-placed class="designer-field-list"></div>
            </aside>
        </div>
        <noscript><p class="alert alert-warning">Enable JavaScript to use the visual document designer.</p></noscript>
    </div>
</section>
@endif
@endsection
