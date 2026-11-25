<?php

namespace App\Services;

use App\Models\QrTicket;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrTicketRenderer
{
    public function render(QrTicket $ticket): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 4), new SvgImageBackEnd));
        $svg = $writer->writeString($ticket->encodedPayload());

        // Modules are 8.65 px at 320 px, so edges fall between pixels. crispEdges keeps them pure
        // black on white instead of anti-aliased grey, which helps the scanner's thresholding.
        return preg_replace('/<svg /', '<svg shape-rendering="crispEdges" ', $svg, 1);
    }
}
