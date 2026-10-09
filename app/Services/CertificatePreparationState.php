<?php

namespace App\Services;

use App\Models\Reservation;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class CertificatePreparationState
{
    public function isPrepared(Reservation $reservation): bool
    {
        // Historical preparation stays read-only. Viewing a PDF does not prove physical preparation.
        $document = $reservation->document()->first();
        $disk = Storage::disk('local');
        if (! $document || ! $document->hasSafePath() || ! $disk->exists($document->stored_path)
            || $disk->size($document->stored_path) !== $document->file_size || $document->file_size < 1) {
            return false;
        }
        // Compatibility only: a broken historical package must not suppress the Ready warning.
        // This never enables upload, conversion, generation, preview or download of legacy files.
        $zip = new ZipArchive;
        if ($zip->open($disk->path($document->stored_path), ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false
                || ($zip->statName('word/document.xml')['size'] ?? 0) > 5 * 1024 * 1024) {
                return false;
            }
            $content = $zip->getFromName('word/document.xml');
            if (! is_string($content) || preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
                return false;
            }
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = new DOMDocument;
                if (! $xml->loadXML($content, LIBXML_NONET) || $xml->doctype !== null) {
                    return false;
                }
                $xpath = new DOMXPath($xml);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

                return $xpath->query('/w:document/w:body')->length === 1;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        } finally {
            $zip->close();
        }
    }
}
