<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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

        $disk = Storage::disk('local');

        return $pdf && $pdf->id === $this->service_document_template_id && hash_equals($this->source_revision, $pdf->revision())
            && is_string($this->source_content_hash) && preg_match('/\A[0-9a-f]{64}\z/', $this->source_content_hash)
            && preg_match('#\Aservice-document-templates/'.$this->service_id.'/[A-Za-z0-9]{40}\.pdf\z#D', $pdf->stored_path)
            && $disk->exists($pdf->stored_path)
            && hash_equals($this->source_content_hash, hash_file('sha256', $disk->path($pdf->stored_path)));
    }
}
