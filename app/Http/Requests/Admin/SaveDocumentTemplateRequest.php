<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['reconfigure' => ['sometimes', 'boolean'], 'is_active' => ['required', 'boolean'],
            'pdf_id' => [($this->isMethod('post') || $this->boolean('reconfigure')) ? 'required' : 'nullable', 'integer', 'min:1'],
            'pdf_revision' => [($this->isMethod('post') || $this->boolean('reconfigure')) ? 'required' : 'nullable', 'string', 'size:64'],
            'version' => [$this->isMethod('patch') ? 'required' : 'nullable', 'integer', 'min:1', 'max:2147483647']];
    }
}
