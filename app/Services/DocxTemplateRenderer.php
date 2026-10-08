<?php

namespace App\Services;

use App\Rules\OfficialDocumentTemplate;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Exception\Exception as PhpWordException;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use Throwable;
use ZipArchive;

class DocxTemplateRenderer
{
    public function validFile(string $path): bool
    {
        if (! is_file($path) || Validator::make(['document' => new UploadedFile($path, 'document.docx', null, null, true)],
            ['document' => ['file', 'max:10240', new OfficialDocumentTemplate]])->fails()) {
            return false;
        }
        try {
            $this->variables($path);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function render(string $master, string $output, array $values): void
    {
        if (! $this->validFile($master)) {
            $this->fail('The official DOCX template is corrupt or unsupported. Upload a valid template before preparing this document.');
        }
        $tempDir = Settings::getTempDir();
        $escaping = Settings::isOutputEscapingEnabled();
        $processor = null;
        try {
            Settings::setTempDir(dirname($output));
            Settings::setOutputEscapingEnabled(true);
            // Inspect all Word XML parts, including parts the library does not merge.
            $fields = $this->variables($master);
            $unsupported = array_diff($fields, array_keys(DocumentMergeFields::LABELS));
            if ($unsupported) {
                $this->fail('This template contains unsupported merge fields: '.implode(', ', array_map(fn ($field) => '${'.$field.'}', $unsupported)));
            }
            $processor = new TemplateProcessor($master);
            if (array_diff($fields, $processor->getVariables())) {
                $this->fail('Place merge fields in the DOCX document body, headers or footers. This template contains fields in an unsupported document part.');
            }
            $tokens = [];
            foreach ($fields as $field) {
                if (! isset($values[$field]) || trim((string) $values[$field]) === '') {
                    $guidance = str_starts_with($field, 'resident_')
                        ? ' The Resident must correct missing profile information through their profile.'
                        : ' Only use merge fields whose source values are recorded.';
                    $this->fail('Document preparation cannot continue because '.DocumentMergeFields::LABELS[$field].' is missing.'.$guidance);
                }
                // Word template replacement is single-line plain text, never executable content.
                $value = preg_replace('/[\r\n\t]+/u', ' ', (string) $values[$field]);
                if (str_contains($value, '${') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                    $this->fail(DocumentMergeFields::LABELS[$field].' contains reserved merge syntax or invalid text. Correct the source information before preparing the document.');
                }
                $token = 'sb_'.Str::random(40);
                $processor->setValue($field, '${'.$token.'}');
                $tokens[$token] = $value;
            }
            // Random intermediate tokens prevent values from becoming other merge fields.
            $processor->setValues($tokens);
            $this->save($processor, $output);
            if (! $this->validFile($output) || $this->variables($output)) {
                $this->fail('The document could not be prepared completely. Review the DOCX template before trying again.');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('Document preparation failed. Review the official DOCX template and try again. The previous prepared document is preserved.');
        } finally {
            unset($processor);
            Settings::setTempDir($tempDir);
            Settings::setOutputEscapingEnabled($escaping);
        }
    }

    private function variables(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            $this->fail('The DOCX document cannot be read.');
        }
        $fields = [];
        $previous = libxml_use_internal_errors(true);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! preg_match('#^word/.*\.(xml|rels)$#D', $name)) {
                    continue;
                }
                $xml = new DOMDocument;
                if (! $xml->loadXML($zip->getFromIndex($i), LIBXML_NONET) || $xml->doctype) {
                    $this->fail('The DOCX document contains invalid or unsafe XML.');
                }
                $xpath = new DOMXPath($xml);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $text = '';
                foreach ($xpath->query('//w:t') as $node) {
                    $text .= $node->textContent;
                }
                // Also reject unresolved fields in links, instructions and other XML metadata.
                foreach ($xpath->query('//@* | //text()[not(parent::w:t)]') as $node) {
                    $text .= "\n".$node->nodeValue;
                }
                preg_match_all('/\$\{([^{}]+)\}/u', $text, $matches);
                $fields = array_merge($fields, $matches[1]);
                if (str_contains(preg_replace('/\$\{[^{}]+\}/u', '', $text), '${')) {
                    $this->fail('This template contains an incomplete merge field. Correct its ${field_name} syntax before preparing the document.');
                }
            }
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return array_values(array_unique($fields));
    }

    private function save(TemplateProcessor $processor, string $output): void
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $processor->saveAs($output);

                return;
            } catch (PhpWordException $exception) {
                // Windows can briefly lock the library's temporary ZIP during atomic replacement.
                // Retry only that save failure, without touching the master or committed document.
                if (PHP_OS_FAMILY !== 'Windows' || $attempt >= 2 || ! str_starts_with($exception->getMessage(), 'Could not close zip file')) {
                    throw $exception;
                }
                usleep(100000 * ($attempt + 1));
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
