<?php

namespace App\Http\Requests\Admin;

use App\Enums\DocumentFieldType;
use App\Enums\UserRole;
use App\Models\DocumentField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDocumentFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('field_type') === 'dropdown' && is_string($this->input('options_text'))) {
            $this->merge(['options' => array_values(array_filter(array_map('trim', preg_split('/\R/', $this->input('options_text'))), fn ($value) => $value !== ''))]);
        }
        foreach (['label', 'field_name'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        $template = $this->route('service')->dynamicDocumentTemplate;
        $field = $template?->fields()->find($this->route('documentField'));
        $rules = [
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'field_name' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]*\z/',
                Rule::unique('document_fields')->where('document_template_id', $template?->id)->ignore($field)],
            'label' => ['required', 'string', 'max:150'], 'source' => ['required', Rule::in(['system', 'custom'])],
            'field_type' => ['required', Rule::enum(DocumentFieldType::class)], 'is_required' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,10000'],
            'max_length' => ['nullable', 'prohibited_unless:field_type,text', 'integer', 'between:1,1000'],
            'min_value' => ['nullable', 'prohibited_unless:field_type,number', 'numeric', 'between:-1000000000,1000000000'],
            'max_value' => ['nullable', 'prohibited_unless:field_type,number', 'numeric', 'between:-1000000000,1000000000'],
            'options_text' => ['nullable', 'prohibited_unless:field_type,dropdown', 'string', 'max:6000'],
            'options' => ['prohibited_unless:field_type,dropdown', 'required_if:field_type,dropdown', 'array', 'min:1', 'max:50'],
            'options.*' => ['required', 'string', 'max:100', 'distinct:strict'],
            'validation_rules' => ['prohibited'],
            'has_position' => ['required', 'boolean'],
            'position' => ['prohibited_unless:has_position,1', Rule::requiredIf($this->boolean('has_position')),
                'array:page_number,x_position,y_position,width,height,font_size,alignment,text_behavior'],
        ];
        foreach (['page_number' => ['integer', 'between:1,1000'], 'x_position' => ['numeric', 'between:0,20000'],
            'y_position' => ['numeric', 'between:0,20000'], 'width' => ['numeric', 'between:1,20000'],
            'height' => ['numeric', 'between:1,20000'], 'font_size' => ['numeric', 'between:4,72'],
            'alignment' => [Rule::in(['left', 'center', 'right'])], 'text_behavior' => [Rule::in(['auto_resize', 'wrap'])]] as $key => $constraints) {
            $rules['position.'.$key] = [Rule::requiredIf($this->boolean('has_position')), ...$constraints];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $name = $this->input('field_name');
            if ($this->input('source') === 'system' && (! is_string($name) || ! isset(DocumentField::SYSTEM_FIELDS[$name])
                || DocumentField::SYSTEM_FIELDS[$name] !== $this->input('field_type'))) {
                $validator->errors()->add('field_name', 'Choose a supported system name and its matching type.');
            }
            if ($this->input('source') === 'custom' && is_string($name) && isset(DocumentField::SYSTEM_FIELDS[$name])) {
                $validator->errors()->add('field_name', 'This name is reserved for a system field.');
            }
            if (is_numeric($this->input('min_value')) && is_numeric($this->input('max_value'))
                && $this->input('min_value') > $this->input('max_value')) {
                $validator->errors()->add('max_value', 'Maximum value must be at least the minimum value.');
            }
        }];
    }

    public function fieldData(): array
    {
        $data = $this->validated();
        $rules = [];
        foreach (['max_length' => 'max_length', 'min_value' => 'min', 'max_value' => 'max'] as $input => $key) {
            if (isset($data[$input])) {
                $rules[$key] = $input === 'max_length' ? (int) $data[$input] : (float) $data[$input];
            }
        }

        return [...$this->safe()->only(['field_name', 'label', 'source', 'field_type', 'is_required', 'sort_order']),
            'validation_rules' => $rules ?: null, 'options' => $data['field_type'] === 'dropdown' ? $data['options'] : null];
    }
}
