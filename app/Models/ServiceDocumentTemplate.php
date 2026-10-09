<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceDocumentTemplate extends Model
{
    protected $fillable = ['original_filename', 'stored_path', 'mime_type', 'file_size', 'uploaded_by', 'uploaded_at'];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'uploaded_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function format(): string
    {
        return $this->mime_type === 'application/pdf' ? 'PDF' : 'Legacy file — replace with PDF';
    }

    public function revision(): string
    {
        return hash('sha256', $this->stored_path);
    }
}
