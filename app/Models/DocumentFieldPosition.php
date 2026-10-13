<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentFieldPosition extends Model
{
    protected $fillable = ['page_number', 'x_position', 'y_position', 'width', 'height', 'font_size', 'alignment', 'text_behavior'];

    protected function casts(): array
    {
        return ['page_number' => 'integer', 'x_position' => 'decimal:3', 'y_position' => 'decimal:3',
            'width' => 'decimal:3', 'height' => 'decimal:3', 'font_size' => 'decimal:2'];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(DocumentField::class, 'document_field_id');
    }
}
