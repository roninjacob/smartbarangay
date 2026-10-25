@extends('layouts.authenticated')
@section('dashboard')
<a class="service-back-link" href="{{ $backUrl }}">&larr; Back to reservation details</a>
<section class="dashboard-panel builder-panel">
    <div class="dashboard-panel-heading"><div><h2>{{ $reservation->service->name }}</h2><p class="panel-description">Review the personalized certificate. Finalizing saves this exact PDF without changing request status.</p></div></div>
    <div class="builder-body">
        @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
        @if(!$artifact)
            <p class="alert alert-warning" role="alert">{{ $previewError ?? \App\Services\ReservationDocumentPreview::UNAVAILABLE }}</p>
        @else
            @include('admin.services.document-preview', ['preview' => ['kind' => 'pdf', 'url' => route($documentRole.'.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']])], 'previewHeading' => 'Personalized Certificate Preview'])
            @if($documentRole === 'admin' && $reservation->status->canPrepareDocument())
                <form method="POST" action="{{ route('admin.reservations.generated.store', $reservation) }}" class="mt-3" data-finalize-preview>
                    @csrf
                    <input type="hidden" name="preview_token" value="{{ $artifact['token'] }}">
                    <button type="submit" class="btn btn-primary" disabled data-finalize-button>Finalize Certificate</button>
                    <p class="app-note mt-2">Review every page before finalizing. This preview expires after 30 minutes. An expired or changed preview must be reviewed again.</p>
                </form>
            @endif
            <details class="mt-3"><summary>Recorded document information</summary><dl class="reservation-summary document-information-summary">@foreach($reservation->documentAnswers as $answer)<div><dt>{{ $answer->field_snapshot['label'] }}</dt><dd>{{ $answer->answer ?? 'Not provided' }}</dd></div>@endforeach</dl></details>
        @endif
    </div>
</section>
@endsection
