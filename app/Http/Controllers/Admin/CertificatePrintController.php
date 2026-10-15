<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CertificatePrintRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CertificatePrintController extends Controller
{
    public function show(Request $request, string $token, CertificatePrintRenderer $renderer): Response
    {
        try {
            $artifact = $renderer->artifact($token, $request->user());
        } catch (HttpException $exception) {
            return $this->unavailable($exception);
        }

        return response()->view('admin.certificates.print', ['artifact' => $artifact, 'token' => $token, 'preview' => ['kind' => 'pdf', 'url' => route('admin.certificates.print.pdf', $token), 'prepared' => true], 'printDocument' => true])
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function pdf(Request $request, string $token, CertificatePrintRenderer $renderer): Response
    {
        try {
            $artifact = $renderer->artifact($token, $request->user());
        } catch (HttpException $exception) {
            return $this->unavailable($exception);
        }

        return response($artifact['pdf'])->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="Certificate.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; sandbox");
    }

    private function unavailable(HttpException $exception): Response
    {
        if ($exception->getStatusCode() !== 410) {
            throw $exception;
        }

        return response()->view('admin.certificates.unavailable', ['message' => CertificatePrintRenderer::OBSOLETE], 410)
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
