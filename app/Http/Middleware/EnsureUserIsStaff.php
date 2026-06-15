<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStaff
{
    /**
     * The dealer console is staff-only. Buyers authenticate against the same
     * users table (shared `web` guard) but carry a null dealer_id — they must
     * never reach dealer routes just by being signed in. A signed-in buyer is
     * sent to their garage; a guest goes to the staff login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('dealer.login');
        }

        if ($user->dealer_id === null) {
            return redirect()->route('garage');
        }

        return $next($request);
    }
}
