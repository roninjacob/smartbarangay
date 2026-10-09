<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Rules\OfficialDocumentTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['name', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('services', 'name')->ignore($this->route('service'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'official_template' => ['bail', 'nullable', 'file', 'extensions:pdf', 'max:10240', new OfficialDocumentTemplate],
        ];
    }
}
