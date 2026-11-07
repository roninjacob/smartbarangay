<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IotScannerSessions;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function respond(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
    }
}
