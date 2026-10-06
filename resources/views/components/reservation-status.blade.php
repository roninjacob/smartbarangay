@props(['status'])
<span class="reservation-status status-{{ $status->value }}"><span aria-hidden="true"></span>{{ $status->label() }}</span>
