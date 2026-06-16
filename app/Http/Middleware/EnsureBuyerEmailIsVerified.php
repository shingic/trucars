<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureBuyerEmailIsVerified
{
    /**
     * Signed-in buyers who haven't confirmed their email are redirected to the
     * verify page. Guests pass through untouched (auth middleware handles them).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $buyer = Auth::user();

        if ($buyer && $buyer->email_verified_at === null) {
            return redirect()->route('verify');
        }

        return $next($request);
    }
}
