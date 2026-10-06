<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceRequirementRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('service_requirements', 'name')
                ->where('service_id', $this->route('service')->id)->ignore($this->route('serviceRequirement'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_required' => ['required', 'boolean'],
        ];
    }
}
