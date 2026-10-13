<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\DocumentField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDocumentLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'between:1,2147483647'],
            'pdf_revision' => ['required', 'string', 'size:64'],
            'placements' => ['present', 'array', 'max:300'],
            'placements.*' => ['array:field_id,system_name,page_number,x_position,y_position,width,height,font_size,alignment,text_behavior'],
            'placements.*.field_id' => ['nullable', 'integer', 'min:1'],
            'placements.*.system_name' => ['nullable', 'string', Rule::in(array_keys(DocumentField::SYSTEM_FIELDS))],
            'placements.*.page_number' => ['required', 'integer', 'between:1,1000'],
            'placements.*.x_position' => ['required', 'numeric', 'between:0,20000'],
            'placements.*.y_position' => ['required', 'numeric', 'between:0,20000'],
            'placements.*.width' => ['required', 'numeric', 'between:1,20000'],
            'placements.*.height' => ['required', 'numeric', 'between:1,20000'],
            'placements.*.font_size' => ['required', 'numeric', 'between:4,72'],
            'placements.*.alignment' => ['required', Rule::in(['left', 'center', 'right'])],
            'placements.*.text_behavior' => ['required', Rule::in(['auto_resize', 'wrap'])],
        ];
    }
}
