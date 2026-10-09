<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

class OfficialDocumentTemplate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()
            || strtolower($value->getClientOriginalExtension()) !== 'pdf'
            || File::mimeType($value->getRealPath()) !== 'application/pdf'
            || ! $this->validPdf($value->getRealPath())) {
            $fail('Upload a valid, unencrypted PDF matching its .pdf extension (maximum 10 MB).');
        }
    }

    public function validPdf(string $path): bool
    {
        if (! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            return false;
        }
        try {
            $bytes = file_get_contents($path);
            if (! preg_match('/^%PDF-\d\.\d/', $bytes) || ! preg_match('/%%EOF\s*$/D', $bytes)
                || ! preg_match('/startxref\s+([0-9]+)\s+%%EOF\s*$/D', $bytes, $xref)
                || (int) $xref[1] >= strlen($bytes)) {
                return false;
            }
            $config = new Config;
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(20 * 1024 * 1024);
            $document = (new Parser([], $config))->parseContent($bytes);

            return count($document->getObjectsByType('Catalog')) === 1 && count($document->getPages()) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
