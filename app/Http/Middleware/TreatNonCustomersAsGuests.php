<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TreatNonCustomersAsGuests
{
    /**
     * The marketplace is for customers. Sessions are host-only, so a staff or
     * owner session shouldn't reach it — but if one ever does (e.g. a shared
     * SESSION_DOMAIN), it's treated as a guest for this request rather than as a
     * buyer. The session itself is left alone so the other portal isn't signed out.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (! $user->isCustomer() || $user->isSuspended())) {
            Auth::guard()->forgetUser();
        }

        return $next($request);
    }
}
