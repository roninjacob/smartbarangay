<?php

namespace App\Http\Middleware;

use App\Enums\ResidencyStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResidencyApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->fresh()?->canSubmitReservations(), 403, ResidencyStatus::BLOCKED_MESSAGE);

        return $next($request);
    }
}
