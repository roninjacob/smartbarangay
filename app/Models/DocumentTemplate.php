<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTemplate extends Model
{
    protected $fillable = ['is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function officialPdf(): BelongsTo
    {
        return $this->belongsTo(ServiceDocumentTemplate::class, 'service_document_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class);
    }

    public function matchesCurrentPdf(): bool
    {
        $pdf = $this->service->documentTemplate;

        return $pdf && $pdf->id === $this->service_document_template_id && hash_equals($this->source_revision, $pdf->revision());
    }
}
