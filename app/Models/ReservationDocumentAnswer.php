<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationDocumentAnswer extends Model
{
    protected $fillable = ['document_field_id', 'field_snapshot', 'answer'];

    protected function casts(): array
    {
        return ['field_snapshot' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(DocumentField::class, 'document_field_id');
    }
}
