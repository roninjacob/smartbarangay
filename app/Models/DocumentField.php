<?php

namespace App\Models;

use App\Enums\DocumentFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentField extends Model
{
    public const SYSTEM_FIELDS = [
        'resident_name' => 'text', 'resident_address' => 'text', 'resident_contact' => 'text',
        'date_issued' => 'date', 'barangay_name' => 'text',
        'resident_birthdate' => 'date', 'resident_civil_status' => 'text', 'resident_occupation' => 'text',
        'resident_address_street' => 'text', 'resident_address_zone_purok' => 'text',
        'resident_first_name' => 'text', 'resident_middle_name' => 'text', 'resident_last_name' => 'text',
        'resident_suffix' => 'text', 'resident_age' => 'number', 'resident_house_unit_number' => 'text',
        'municipality_name' => 'text', 'province_name' => 'text',
        'document_date_full' => 'text', 'document_day' => 'number', 'document_day_ordinal' => 'text',
        'document_month' => 'text', 'document_month_number' => 'number',
        'document_month_abbreviated' => 'text', 'document_year' => 'number',
    ];

    public const SYSTEM_LABELS = [
        'resident_name' => 'Full Name', 'resident_first_name' => 'First Name', 'resident_middle_name' => 'Middle Name',
        'resident_last_name' => 'Last Name', 'resident_suffix' => 'Suffix', 'resident_birthdate' => 'Date of Birth',
        'resident_age' => 'Age at Submission', 'resident_contact' => 'Contact Number',
        'resident_civil_status' => 'Civil Status', 'resident_occupation' => 'Occupation',
        'resident_address' => 'Complete Address', 'resident_house_unit_number' => 'House / Unit Number',
        'resident_address_street' => 'Street / Sitio / Purok', 'resident_address_zone_purok' => 'Additional Zone / Purok',
        'barangay_name' => 'Barangay', 'municipality_name' => 'Municipality', 'province_name' => 'Province',
        'date_issued' => 'Document Date (ISO)', 'document_date_full' => 'Document Date (Full)',
        'document_day' => 'Document Day', 'document_day_ordinal' => 'Document Day (Ordinal)',
        'document_month' => 'Document Month', 'document_month_number' => 'Document Month Number',
        'document_month_abbreviated' => 'Document Month Abbreviated', 'document_year' => 'Document Year',
    ];

    protected $fillable = ['field_name', 'label', 'source', 'field_type', 'is_required', 'validation_rules', 'options', 'sort_order'];

    protected function casts(): array
    {
        return ['field_type' => DocumentFieldType::class, 'is_required' => 'boolean', 'validation_rules' => 'array', 'options' => 'array', 'sort_order' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(DocumentFieldPosition::class);
    }
}
