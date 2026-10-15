<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Tfpdf\Fpdi;
use Throwable;

class DocumentLayout
{
    // Stored coordinates have three decimal places. Integer arithmetic permits exact edge contact.
    public function validate(string $path, array $positions, string $key = 'layout'): void
    {
        $pdf = new Fpdi('P', 'pt');
        try {
            $count = $pdf->setSourceFile($path);
            $pages = [];
            for ($page = 1; $page <= $count; $page++) {
                $pages[$page] = $pdf->getTemplateSize($pdf->importPage($page));
            }
            $boxes = [];
            foreach ($positions as $position) {
                $page = $position['page_number'] ?? 0;
                if (! is_numeric($page) || (int) $page != $page || ! isset($pages[$page])) {
                    $this->fail($key);
                }
                $values = [];
                foreach (['x_position', 'y_position', 'width', 'height', 'font_size'] as $name) {
                    if (! is_numeric($position[$name] ?? null) || ! is_finite((float) $position[$name])) {
                        $this->fail($key);
                    }
                    $values[$name] = (int) round((float) $position[$name] * 1000);
                }
                ['x_position' => $x, 'y_position' => $y, 'width' => $w, 'height' => $h, 'font_size' => $font] = $values;
                if ($x < 0 || $y < 0 || $w <= 0 || $h <= 0 || $font < 4000 || $font > 72000
                    || $x + $w > (int) round($pages[$page]['width'] * 1000)
                    || $y + $h > (int) round($pages[$page]['height'] * 1000)
                    || ! in_array($position['alignment'] ?? null, ['left', 'center', 'right'], true)
                    || ! in_array($position['text_behavior'] ?? null, ['auto_resize', 'wrap'], true)) {
                    $this->fail($key);
                }
                foreach ($boxes[$page] ?? [] as [$bx, $by, $bw, $bh]) {
                    if ($x < $bx + $bw && $x + $w > $bx && $y < $by + $bh && $y + $h > $by) {
                        $this->fail($key);
                    }
                }
                $boxes[$page][] = [$x, $y, $w, $h];
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail($key);
        } finally {
            $pdf->cleanUp(true);
        }
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages([$key => 'Review the document layout: fields must fit valid PDF pages and must not overlap. Touching edges and corners are allowed.']);
    }
}
