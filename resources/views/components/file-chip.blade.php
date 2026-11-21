@props(['name', 'size' => null, 'href' => null, 'label' => null, 'linkText' => 'View'])
@php
    $extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE');
    $kind = in_array($extension, ['JPG', 'JPEG', 'PNG', 'WEBP'], true) ? 'image' : ($extension === 'PDF' ? 'pdf' : 'file');
@endphp
{{-- A submitted file: type badge, name, size and a view/download link. --}}
<div {{ $attributes->class(['rv-file', 'is-'.$kind]) }}>
    <span class="rv-file-type" aria-hidden="true">{{ $extension }}</span>
    <span class="rv-file-text">
        @if($label)<span class="rv-file-label">{{ $label }}</span>@endif
        <strong class="rv-file-name">{{ $name }}</strong>
        @if($size !== null)<span class="rv-file-size">{{ number_format($size / 1024, 1) }} KB</span>@endif
    </span>
    @if($href)<a class="rv-file-link" href="{{ $href }}" aria-label="{{ $linkText }} {{ $name }}">{{ $linkText }} <x-app-icon name="arrow"/></a>@endif
</div>
