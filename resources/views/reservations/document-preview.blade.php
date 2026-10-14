@extends('layouts.authenticated')
@section('dashboard')
<a class="service-back-link" href="{{ $backUrl }}">&larr; Back to reservation details</a>
<section class="dashboard-panel builder-panel">
    <div class="dashboard-panel-heading"><div><h2>{{ $reservation->service->name }}</h2><p class="panel-description">Temporary document preview using the information recorded with your request. This is not the official print output.</p></div></div>
    <div class="builder-body">
    @if(!$previewData)
        <p class="alert alert-warning" role="alert">{{ \App\Services\ReservationDocumentPreview::UNAVAILABLE }}</p>
    @else
        <div data-filled-preview>
            <script type="application/json" data-filled-data>{!! json_encode($previewData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
            <div class="designer-pagination mb-3"><button class="btn btn-outline-secondary" type="button" data-filled-previous disabled>Previous Page</button><span data-filled-page>Loading document…</span><button class="btn btn-outline-secondary" type="button" data-filled-next disabled>Next Page</button></div>
            <p role="status" aria-live="polite" data-filled-status>Loading historical document preview…</p>
            <div class="filled-paper-area"><div class="filled-sheet" data-filled-sheet hidden><canvas data-filled-canvas role="img" aria-label="Filled document preview"></canvas><div class="filled-overlay" data-filled-overlay></div></div></div>
            <details class="mt-3"><summary>Recorded document information</summary><dl class="reservation-summary document-information-summary">@foreach(collect($previewData['placements'])->unique('label') as $field)<div><dt>{{ $field['label'] }}</dt><dd>{{ $field['text'] }}</dd></div>@endforeach</dl></details>
        </div>
    @endif
    </div>
</section>
@endsection
