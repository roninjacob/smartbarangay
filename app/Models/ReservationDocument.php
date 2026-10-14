<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationDocument extends Model
{
    protected $attributes = ['template_version' => null, 'content_hash' => null];

    protected $fillable = ['service_document_template_id', 'template_revision', 'template_version', 'content_hash', 'template_filename', 'original_filename', 'stored_path', 'mime_type', 'file_size', 'generated_by', 'generated_at'];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'generated_at' => 'datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ServiceDocumentTemplate::class, 'service_document_template_id');
    }

    public function hasSafePath(): bool
    {
        $extension = $this->mime_type === 'application/pdf' ? 'pdf' : 'docx';

        return (bool) preg_match('#^reservation-documents/'.preg_quote((string) $this->reservation_id, '#').'/[A-Za-z0-9]{40}\\.'.$extension.'$#D', $this->stored_path);
    }
}
