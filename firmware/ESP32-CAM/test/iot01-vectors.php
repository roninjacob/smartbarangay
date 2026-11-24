<?php
// Host-only synthetic QR fixtures. Reads BaconQrCode via an explicit autoload
// path; does not boot Laravel, read .env, use real tickets, or query a database.
require $argv[1];
$vectors = json_decode(file_get_contents(__DIR__.'/iot01-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
$output = $argv[2];
foreach ($vectors as $vector) {
    $qr = BaconQrCode\Encoder\Encoder::encode($vector['payload'], BaconQrCode\Common\ErrorCorrectionLevel::L());
    $matrix = $qr->getMatrix();
    $scale = 6;
    $border = 4;
    $size = ($matrix->getWidth() + 2 * $border) * $scale;
    $pixels = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $mx = intdiv($x, $scale) - $border;
            $my = intdiv($y, $scale) - $border;
            $black = $mx >= 0 && $my >= 0 && $mx < $matrix->getWidth() && $my < $matrix->getHeight() && $matrix->get($mx, $my) === 1;
            $pixels .= $black ? "\0" : "\xff";
        }
    }
    file_put_contents($output.'/'.$vector['name'].'.pgm', "P5\n$size $size\n255\n".$pixels);
    // SVG is convenient for phone/paper manual trials; same encoder and border.
    $writer = new BaconQrCode\Writer(new BaconQrCode\Renderer\ImageRenderer(
        new BaconQrCode\Renderer\RendererStyle\RendererStyle(320, 4),
        new BaconQrCode\Renderer\Image\SvgImageBackEnd()
    ));
    file_put_contents($output.'/'.$vector['name'].'.svg', $writer->writeString($vector['payload']));
}
echo 'Generated '.count($vectors)." synthetic QR vectors; no application boot/data access.\n";
