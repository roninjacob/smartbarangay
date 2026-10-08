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

        return $writer->writeString($ticket->encodedPayload());
    }
}
