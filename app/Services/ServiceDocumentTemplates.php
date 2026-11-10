<?php

namespace App\Services;

use App\Models\Service;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

class ServiceDocumentTemplates
{
    // Caller holds the service lock and transaction. Return the previous path for cleanup after commit.
    public function replace(Service $service, UploadedFile $file, int $adminId, array &$newPaths): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = 'service-document-templates/'.$service->id.'/'.Str::random(40).'.'.$extension;
        $newPaths[] = $path;
        $disk = Storage::disk('local');
        try {
            $written = $disk->putFileAs(dirname($path), $file, basename($path));
            $persisted = $written && $disk->exists($path) && $disk->size($path) === $file->getSize()
                && is_readable($disk->path($path));
            if ($persisted) {
                $originalHash = @hash_file('sha256', $file->getRealPath());
                $storedHash = @hash_file('sha256', $disk->path($path));
                $persisted = is_string($originalHash) && is_string($storedHash) && hash_equals($originalHash, $storedHash);
            }
        } catch (FilesystemException) {
            $persisted = false;
        }
        if (! $persisted) {
            throw ValidationException::withMessages(['official_template' => 'The official PDF could not be saved and verified in private storage. Ask the administrator to check storage permissions and available space, then try again.']);
        }
        $current = $service->documentTemplate()->first();
        $previous = $current?->stored_path;
        $audit = app(ConfigurationAudit::class);
        $old = $current ? $audit->values($current) : [];
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'Official template.'.$extension;
        $saved = $service->documentTemplate()->updateOrCreate([], [
            'original_filename' => mb_substr($name, 0, 240), 'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => $file->getSize(), 'uploaded_by' => $adminId, 'uploaded_at' => now(),
        ]);

        $audit->record(User::findOrFail($adminId), $saved, $current ? 'updated' : 'created', $old, $audit->values($saved));

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
