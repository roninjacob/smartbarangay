@props(['status', 'compact' => false])
@php
    use App\Enums\ReservationStatus;

    $flow = [ReservationStatus::Pending, ReservationStatus::UnderReview, ReservationStatus::Approved, ReservationStatus::ReadyForPickup, ReservationStatus::Completed];
    $terminal = in_array($status, [ReservationStatus::Rejected, ReservationStatus::Cancelled], true);
    $currentIndex = $terminal ? -1 : array_search($status, $flow, true);
@endphp
{{-- Processing progress. Rejected and Cancelled are terminal states shown after the track. --}}
<div {{ $attributes->class(['rv-stepper', 'is-compact' => $compact, 'is-terminal' => $terminal, 'tone-'.$status->value]) }}>
    <ol class="rv-stepper-track" aria-label="Request progress">
        @foreach($flow as $step)
            @php($state = $terminal ? 'is-muted' : ($loop->index < $currentIndex ? 'is-done' : ($loop->index === $currentIndex ? 'is-current' : 'is-upcoming')))
            <li class="rv-step {{ $state }} tone-{{ $step->value }}" @if($state === 'is-current') aria-current="step" @endif>
                <span class="rv-step-dot" aria-hidden="true">@if($state === 'is-done')✓@else{{ $loop->iteration }}@endif</span>
                <span class="rv-step-label">@if($state === 'is-current')<span class="visually-hidden">Current status: </span>@endif{{ $step->label() }}@if($state === 'is-done')<span class="visually-hidden"> (done)</span>@endif</span>
            </li>
        @endforeach
    </ol>
    @if($terminal)
        <p class="rv-stepper-terminal"><x-reservation-status :status="$status"/> <span>{{ $status === ReservationStatus::Rejected ? 'This request was not approved.' : 'This request was cancelled and will not be processed.' }}</span></p>
    @endif
</div>
