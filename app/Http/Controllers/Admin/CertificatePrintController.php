<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CertificatePrintRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CertificatePrintController extends Controller
{
    public function show(Request $request, string $token, CertificatePrintRenderer $renderer): Response
    {
        $artifact = $renderer->artifact($token, $request->user());

        return response()->view('admin.certificates.print', ['artifact' => $artifact, 'token' => $token, 'preview' => ['kind' => 'pdf', 'url' => route('admin.certificates.print.pdf', $token), 'prepared' => true], 'printDocument' => true])
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function pdf(Request $request, string $token, CertificatePrintRenderer $renderer): Response
    {
        $artifact = $renderer->artifact($token, $request->user());

        return response($artifact['pdf'])->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="Certificate.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; sandbox");
    }
}
