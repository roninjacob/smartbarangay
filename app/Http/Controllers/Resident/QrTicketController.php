<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\QrTicket;
use App\Services\QrTicketRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QrTicketController extends Controller
{
    public function index(Request $request): Response
    {
        $tickets = $this->ownedTickets($request)->with(['reservation.service', 'reservation.schedule'])
            ->orderByDesc('generated_at')->orderByDesc('id')->paginate(10);

        return response()->view('resident.qr-tickets.index', [
            ...$this->shellData('My QR Tickets'), 'tickets' => $tickets,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $qrTicket): Response
    {
        $ticket = $this->ownedTickets($request)->whereKey($qrTicket)
            ->with(['reservation.service', 'reservation.schedule'])->firstOrFail();

        return response()->view('resident.qr-tickets.show', [
            ...$this->shellData('QR Ticket Details'), 'ticket' => $ticket, 'reservation' => $ticket->reservation,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function image(Request $request, string $qrTicket, QrTicketRenderer $renderer): Response
    {
        // Resolve ownership and lifecycle before invoking the renderer.
        $ticket = $this->ownedTickets($request)->whereKey($qrTicket)->with('reservation')->firstOrFail();
        abort_unless($ticket->reservation->status->canDisplayQrTicket(), 404);

        return response($renderer->render($ticket), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function ownedTickets(Request $request): Builder
    {
        return QrTicket::whereHas('reservation', fn (Builder $query) => $query->where('user_id', $request->user()->id));
    }

    private function shellData(string $heading): array
    {
        return ['roleLabel' => 'Resident', 'navigation' => config('navigation.resident'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'My QR Tickets'];
    }
}
