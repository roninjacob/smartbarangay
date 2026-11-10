<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IotAutomaticScanner;
use App\Services\IotScannerSessions;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IotScannerController extends Controller
{
    public function devices(Request $request, IotScannerSessions $scanners): JsonResponse
    {
        return $this->respond(['devices' => $scanners->devices($request)]);
    }

    public function challenge(Request $request, IotScannerSessions $scanners): JsonResponse
    {
        $id = $request->input('device_id');
        abort_unless(is_string($id) && preg_match('/\A[0-9a-f-]{36}\z/', $id), 422);
        $result = $scanners->challenge($request, $id);
        $writer = new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd));
        $result['qr_svg'] = $writer->writeString($result['challenge']);
        unset($result['challenge']);

        return $this->respond($result);
    }

    public function poll(Request $request, IotScannerSessions $scanners): JsonResponse
    {
        return $this->respond($scanners->poll($request));
    }

    public function disconnect(Request $request, IotScannerSessions $scanners): JsonResponse
    {
        $scanners->disconnect($request);

        return $this->respond(['state' => 'unpaired']);
    }

    public function connect(Request $request, IotAutomaticScanner $scanner): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'preview' => ['sometimes', 'boolean']]);

        return $this->respond($scanner->connect($request, $data['device_id'], (bool) ($data['preview'] ?? false)));
    }

    public function preview(Request $request, IotAutomaticScanner $scanner): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return $this->respond($scanner->preview($request, (bool) $data['enabled']));
    }

    public function frame(Request $request, IotAutomaticScanner $scanner): Response
    {
        $frame = $scanner->frameSnapshot($request);

        return response($frame['jpeg'])->header('X-SB-Frame-Age-Ms', (string) $frame['age_ms'])
            ->header('X-SB-Frame-Sequence', (string) $frame['sequence'])->header('Content-Type', 'image/jpeg')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function respond(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
    }
}
