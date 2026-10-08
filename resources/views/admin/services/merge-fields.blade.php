<details class="mt-3">
    <summary class="fw-semibold">Supported merge fields (DOCX)</summary>
    <p class="app-note mt-2">Insert these placeholders directly into the DOCX master template. They will be replaced when an Admin prepares an approved request.</p>
    <dl class="row mb-0">
        @foreach(\App\Services\DocumentMergeFields::LABELS as $field => $label)
            <dt class="col-sm-6 text-break"><code>{{ '${'.$field.'}' }}</code></dt><dd class="col-sm-6 text-break">{{ $label }}</dd>
        @endforeach
    </dl>
    <p class="app-note">Only fields used in your template are required. If a value is missing, preparation stops until the source information is corrected. PDF templates remain static references.</p>
    <p class="app-note mb-0">Some existing requests have no recorded purpose. Use <code>${purpose}</code> only when the request has a purpose.</p>
</details>
