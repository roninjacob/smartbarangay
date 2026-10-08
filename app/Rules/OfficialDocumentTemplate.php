<?php

namespace App\Rules;

use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use ZipArchive;

class OfficialDocumentTemplate implements ValidationRule
{
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Please select a successfully uploaded DOCX or PDF template.');

            return;
        }
        $extension = strtolower($value->getClientOriginalExtension());
        $mime = File::mimeType($value->getRealPath());
        if ($extension === 'pdf' && $mime === 'application/pdf') {
            return;
        }
        // Fileinfo may identify a valid OOXML package as ZIP; inspect its contents in either case.
        if ($extension !== 'docx' || ! in_array($mime, [self::DOCX_MIME, 'application/zip'], true)
            || ! $this->validDocx($value->getRealPath())) {
            $fail('The official template must contain a valid DOCX document or PDF matching its file extension. Macros and executable content are not allowed.');
        }
    }

    private function validDocx(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles > 2000) {
                return false;
            }
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    return false;
                }
                $name = $entry['name'];
                $total += $entry['size'];
                // Inspect only, never extract. Bound decompression and reject unsafe embedded payloads.
                if ($total > 100 * 1024 * 1024 || $entry['size'] > 20 * 1024 * 1024
                    || ($entry['encryption_method'] ?? 0) !== 0
                    || isset($names[$name]) || preg_match('#(^/|\\\\|(^|/)\.\.(/|$)|^[A-Za-z]:)#', $name)
                    || preg_match('#(vbaProject|word/(embeddings|activeX)/|\.(exe|dll|com|bat|cmd|ps1|php|js|html?|svg|docm|dotm)$)#i', $name)) {
                    return false;
                }
                $names[$name] = true;
            }
            $types = $this->xml($zip, '[Content_Types].xml');
            $rels = $this->xml($zip, '_rels/.rels');
            $document = $this->xml($zip, 'word/document.xml');
            if (! $types || ! $rels || ! $document) {
                return false;
            }
            $typesXml = $types->saveXML();
            if (preg_match('/macroEnabled|vbaProject|activeX|oleObject/i', $typesXml)) {
                return false;
            }
            $xpath = new DOMXPath($types);
            $xpath->registerNamespace('t', 'http://schemas.openxmlformats.org/package/2006/content-types');
            if ($xpath->query('/t:Types/t:Override[@PartName="/word/document.xml"][@ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"]')->length !== 1) {
                return false;
            }
            $xpath = new DOMXPath($rels);
            $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            if ($xpath->query('/r:Relationships/r:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"][@Target="word/document.xml"][not(@TargetMode="External")]')->length !== 1) {
                return false;
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            return $xpath->query('/w:document/w:body')->length === 1;
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): ?DOMDocument
    {
        $size = $zip->statName($name)['size'] ?? 0;
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            return null;
        }
        $content = $zip->getFromName($name);
        if ($content === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = new DOMDocument;

            return $xml->loadXML($content, LIBXML_NONET) && $xml->doctype === null ? $xml : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
