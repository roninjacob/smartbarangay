<h3 class="h6 mt-4">Submitted requirement files</h3>
@if($reservation->attachments->isEmpty())
    <p class="app-note">No requirement files were submitted through the upload workflow. Older reservations are preserved.</p>
@else
    <ul class="reservation-requirements">
        @foreach($reservation->attachments as $attachment)
            <li><strong>{{ $attachment->serviceRequirement?->name ?? 'Previous service requirement' }}</strong>
                <p>{{ $attachment->original_filename }} · {{ number_format($attachment->file_size / 1024, 1) }} KB</p>
                <a class="btn btn-outline-primary reservation-details-link mt-2" href="{{ route($attachmentRoute, [$reservation, $attachment]) }}" aria-label="Download {{ $attachment->original_filename }}">Download file</a>
            </li>
        @endforeach
    </ul>
    <p class="app-note">Files are submitted evidence and cannot be replaced here. Optional requirements may have no file.</p>
@endif
