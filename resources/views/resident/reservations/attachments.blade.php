<h3 class="rv-subheading">Submitted requirement files</h3>
@if($reservation->attachments->isEmpty())
    <p class="app-note">No requirement files were submitted through the upload workflow. Older reservations are preserved.</p>
@else
    <div class="rv-files">
        @foreach($reservation->attachments as $attachment)
            <x-file-chip :name="$attachment->original_filename" :size="$attachment->file_size" :label="$attachment->requirementSnapshot?->name ?? 'Legacy attachment: original requirement not recorded'"
                :href="route($attachmentRoute, [$reservation, $attachment])" link-text="Download"/>
        @endforeach
    </div>
    <p class="app-note mt-2">Files are submitted evidence and cannot be replaced here. Optional requirements may have no file.</p>
@endif
