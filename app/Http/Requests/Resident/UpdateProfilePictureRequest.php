<?php

namespace App\Http\Requests\Resident;

use App\Enums\UserRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

class UpdateProfilePictureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Resident;
    }

    public function rules(): array
    {
        return ['profile_picture' => ['bail', 'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp',
            'max:2048', 'dimensions:max_width=4096,max_height=4096',
            function (string $attribute, mixed $value, Closure $fail) {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    return;
                }
                $expected = match (strtolower($value->getClientOriginalExtension())) {
                    'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
                    default => null,
                };
                if ($expected !== File::mimeType($value->getRealPath())) {
                    $fail('The picture extension must match its actual image format.');
                }
            }]];
    }
}
