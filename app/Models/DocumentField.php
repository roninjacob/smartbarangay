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
