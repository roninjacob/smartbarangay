<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceDocumentTemplates
{
    // Caller holds the service lock and transaction. Return the previous path for cleanup after commit.
    public function replace(Service $service, UploadedFile $file, int $adminId, array &$newPaths): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = 'service-document-templates/'.$service->id.'/'.Str::random(40).'.'.$extension;
        $newPaths[] = $path;
        if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) {
            throw ValidationException::withMessages(['official_template' => 'The template could not be saved. Please select the file and try again.']);
        }
        $current = $service->documentTemplate()->first();
        $previous = $current?->stored_path;
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'Official template.'.$extension;
        $service->documentTemplate()->updateOrCreate([], [
            'original_filename' => mb_substr($name, 0, 240), 'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => $file->getSize(), 'uploaded_by' => $adminId, 'uploaded_at' => now(),
        ]);

        return $previous;
    }

    public function cleanup(array $paths): void
    {
        // Never delete unrelated private files, including when metadata is corrupt.
        foreach ($paths as $path) {
            if (preg_match('#^service-document-templates/[0-9]+/[A-Za-z0-9]{40}\.(docx|pdf)$#D', $path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
