<?php

namespace App\Services;

use App\Models\Reservation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationFileUploads
{
    public function validate(Request $request, Collection $requirements): array
    {
        $input = $request->all();
        $rules = ['attachments' => ['nullable', 'array']];
        $labels = [];
        if (is_array($input['attachments'] ?? null)) {
            foreach (array_keys($input['attachments']) as $id) {
                if (! $requirements->contains(fn ($requirement) => (string) $requirement->id === (string) $id)) {
                    throw ValidationException::withMessages(['attachments' => 'Please upload files only for this service’s listed requirements.']);
                }
            }
        }
        foreach ($requirements as $requirement) {
            $key = 'attachments.'.$requirement->id;
            $labels[$key] = $requirement->name.' file';
            $rules[$key] = ['bail', $requirement->is_required ? 'required' : 'nullable', 'file',
                'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png',
                'extensions:pdf,jpg,jpeg,png', 'max:5120',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $value instanceof UploadedFile || ! $value->isValid()) {
                        return;
                    }
                    $mime = File::mimeType($value->getRealPath());
                    $expected = match (strtolower($value->getClientOriginalExtension())) {
                        'pdf' => 'application/pdf', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => null,
                    };
                    if ($mime !== $expected || ($mime !== 'application/pdf' && @getimagesize($value->getRealPath()) === false)) {
                        $fail('The file extension must match its actual PDF or image content.');
                    }
                }];
        }
        $validated = Validator::make($input, $rules, [], $labels)->validate();

        return array_filter($validated['attachments'] ?? [], fn ($file) => $file instanceof UploadedFile);
    }

    public function store(Reservation $reservation, array $files, array &$storedPaths): void
    {
        foreach ($files as $requirementId => $file) {
            $path = 'reservation-attachments/'.$reservation->id.'/'.Str::random(40).'.'.$file->extension();
            // Track the generated path before writing so a partial write is also cleaned up.
            $storedPaths[] = $path;
            $saved = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
            if (! $saved) {
                throw ValidationException::withMessages(['attachments.'.$requirementId => 'We could not save this file. Please select your files and try again.']);
            }
            $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'Submitted file';
            $reservation->attachments()->create([
                'service_requirement_id' => $requirementId, 'original_filename' => mb_substr($name, 0, 240),
                'stored_path' => $path, 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(),
            ]);
        }
    }

    public function cleanup(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk('local')->delete($paths);
        }
    }
}
