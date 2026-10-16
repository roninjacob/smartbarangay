<div class="activity-item-heading">
    <h3 class="h6 mb-0">{{ $activity['title'] }}</h3>
    @if($activity['timestamp'])<time datetime="{{ $activity['timestamp']->toIso8601String() }}">{{ $activity['timestamp']->format('M j, Y, g:i:s A') }}</time>@else<span class="text-secondary">Time not recorded</span>@endif
</div>
<p class="activity-actor">{{ $activity['actor'] }}</p>
<p class="mb-0">{{ $activity['description'] }}</p>
@if($activity['qr_used'])<p class="mt-2 mb-0"><span class="badge text-bg-secondary">QR marked Used</span></p>@endif
@if($activity['warning'])<p class="activity-warning mt-2 mb-0">{{ $activity['warning'] }}</p>@endif
@if($activity['note'])<details class="activity-note mt-2"><summary>Processing note</summary><p class="history-note mt-2">{{ $activity['note'] }}</p></details>@endif
