<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationAttachmentController extends Controller
{
    public function resident(Request $request, string $reservation, string $attachment): StreamedResponse
    {
        $owned = $request->user()->reservations()->whereKey($reservation)->firstOrFail();

        return $this->download($owned, $attachment);
    }

    public function admin(Reservation $reservation, string $attachment): StreamedResponse
    {
        return $this->download($reservation, $attachment);
    }

    private function download(Reservation $reservation, string $attachment): StreamedResponse
    {
        $file = $reservation->attachments()->whereKey($attachment)->firstOrFail();
        // Reject corrupt/legacy paths rather than ever serving another private file.
        abort_unless(preg_match('#^reservation-attachments/'.preg_quote((string) $reservation->id, '#').'/[A-Za-z0-9]{40}\.(pdf|jpg|jpeg|png)$#D', $file->stored_path), 404);
        abort_unless(Storage::disk('local')->exists($file->stored_path), 404);

        return Storage::disk('local')->download($file->stored_path, $file->original_filename, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
