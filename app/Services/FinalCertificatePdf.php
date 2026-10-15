<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Tfpdf\Fpdi;

class FinalCertificatePdf
{
    public function render(string $path, array $placements): string
    {
        app(DocumentLayout::class)->validate($path, $placements, 'document');
        $pdf = new Fpdi('P', 'pt');
        try {
            return $this->fill($pdf, $path, $placements);
        } finally {
            $pdf->cleanUp(true);
        }
    }

    private function fill(Fpdi $pdf, string $path, array $placements): string
    {
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
        $count = $pdf->setSourceFile($path);
        foreach ($placements as $position) {
            if (($position['page_number'] ?? 0) < 1 || $position['page_number'] > $count) {
                $this->fail();
            }
        }
        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
            foreach ($placements as $position) {
                if ((int) $position['page_number'] !== $page) {
                    continue;
                }
                $x = (float) $position['x_position'];
                $y = (float) $position['y_position'];
                $width = (float) $position['width'];
                $height = (float) $position['height'];
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $position['text']);
                $font = min(72, max(4, (float) $position['font_size']));
                $wrap = $position['text_behavior'] === 'wrap';
                do {
                    $pdf->SetFont('DejaVu', '', $font);
                    $lines = $wrap ? $this->lines($pdf, $text, $width) : [preg_replace('/\s+/u', ' ', $text)];
                    $fits = count($lines) * $font * 1.2 <= $height && max(array_map(fn ($line) => $pdf->GetStringWidth($line), $lines)) <= $width;
                    if ($fits) {
                        break;
                    }
                    $font -= .25;
                } while ($font >= 4);
                if (! $fits) {
                    throw ValidationException::withMessages(['document' => 'Saved document text does not fit its field box. Review the document layout before generating a certificate.']);
                }
                foreach ($lines as $index => $line) {
                    $offset = match ($position['alignment']) {
                        'center' => ($width - $pdf->GetStringWidth($line)) / 2,
                        'right' => $width - $pdf->GetStringWidth($line),
                        default => 0,
                    };
                    // Text() uses a baseline. The full line height stays within the saved top-left box.
                    $pdf->Text($x + $offset, $y + $font + $index * $font * 1.2, $line);
                }
            }
        }

        return $pdf->Output('S');
    }

    private function lines(Fpdi $pdf, string $text, float $width): array
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($pdf->GetStringWidth($candidate) <= $width) {
                    $line = $candidate;

                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                foreach (mb_str_split($word) as $letter) {
                    if ($line !== '' && $pdf->GetStringWidth($line.$letter) > $width) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $letter;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['document' => 'The saved document layout has overlapping fields or positions outside the PDF pages. Review the layout before generating a certificate.']);
    }
}
