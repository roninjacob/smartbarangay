<?php

namespace Tests\Support;

use setasign\Fpdi\Tfpdf\Fpdi;

class CertificatePdf
{
    public static function twoPages(): string
    {
        $pdf = new Fpdi('P', 'pt');
        foreach ([[595, 842], [420, 595]] as $size) {
            $pdf->AddPage('P', $size);
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Text(30, 30, 'Official sample');
        }

        return $pdf->Output('S');
    }

    public static function bytes(string $text = 'Barangay Calayo official certificate'): string
    {
        $content = 'BT /F1 14 Tf 40 800 Td ('.$text.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
