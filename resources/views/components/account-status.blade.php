@props(['active'])
<span @class(['account-status', 'account-status-active' => $active, 'account-status-inactive' => ! $active])>{{ $active ? 'Active' : 'Inactive' }}</span>
