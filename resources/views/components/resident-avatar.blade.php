@props(['user', 'large' => false, 'initials' => false])
@php($hasPicture = $user->ownedProfilePicturePath() && \Illuminate\Support\Facades\Storage::disk('local')->exists($user->ownedProfilePicturePath()))
@if($hasPicture)
    <img src="{{ route('admin.users.picture', $user) }}" alt="Profile picture of {{ $user->name }}" @class(['resident-avatar', 'resident-avatar-large' => $large]) width="{{ $large ? 80 : 48 }}" height="{{ $large ? 80 : 48 }}" loading="lazy">
@else
    <span @class(['resident-avatar', 'resident-avatar-large' => $large]) role="img" aria-label="Default avatar for {{ $user->name }}">{{ $initials ? mb_strtoupper(collect(preg_split('/\s+/u', trim($user->name)))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('')) : mb_substr($user->name, 0, 1) }}</span>
@endif
