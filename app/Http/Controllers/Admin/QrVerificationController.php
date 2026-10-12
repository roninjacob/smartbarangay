<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QrTicketVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QrVerificationController extends Controller
{
    public function index(): Response
    {
        return response()->view('admin.qr-verification.index', [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => 'QR Verification',
            'pageHeading' => 'QR Verification', 'pageSection' => 'QR Verification',
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function verify(Request $request, QrTicketVerifier $verifier): JsonResponse
    {
        // Invalid input returns a safe result without echoing or flashing the credential.
        try {
            $result = $verifier->verify($request->input('payload'));
        } catch (QueryException $exception) {
            // Do not serialize database errors or log query bindings containing the credential.
            return response()->json(['message' => 'Verification is unavailable. Please try again.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json($result)
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
