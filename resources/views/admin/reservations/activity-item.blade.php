<span class="rv-timeline-dot" aria-hidden="true"></span>
<div class="rv-timeline-head">
    <h3>{{ $activity['title'] }}</h3>
    @if($activity['timestamp'])<time datetime="{{ $activity['timestamp']->toIso8601String() }}">{{ $activity['timestamp']->format('M j, Y, g:i:s A') }}</time>@else<span class="rv-timeline-meta">Time not recorded</span>@endif
</div>
<p class="rv-timeline-meta">{{ $activity['actor'] }}</p>
<p>{{ $activity['description'] }}</p>
@if($activity['qr_used'])<p><span class="rv-badge is-muted">QR marked Used</span></p>@endif
@if($activity['warning'])<p class="rv-note">{{ $activity['warning'] }}</p>@endif
@if($activity['note'])<details><summary>Processing note</summary><p class="rv-note">{{ $activity['note'] }}</p></details>@endif
