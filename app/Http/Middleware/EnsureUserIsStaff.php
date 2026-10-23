<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStaff
{
    /**
     * The dealer console is staff-only. Buyers and owners authenticate against
     * the same users table (shared `web` guard) — being signed in is never
     * enough. A buyer is sent to their garage; a guest to the staff login;
     * an owner, a suspended account or a suspended dealer's staff are refused.
     * Registered as Livewire persistent middleware, so component actions on
     * these pages are re-checked too.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('dealer.login');
        }

        if ($user->isCustomer()) {
            return redirect()->route('garage');
        }

        abort_unless($user->isDealerStaff(), 403, 'This area is for dealer staff.');
        abort_if($user->isSuspended(), 403, 'This account has been suspended. Contact TruCars.');
        abort_if($user->dealer?->isSuspended(), 403, 'This dealership’s portal access is suspended. Contact TruCars.');
        abort_if($user->accessibleRooftopIds() === [], 403, 'You don’t have access to any stores yet. Contact TruCars.');
        abort_if($user->portalDealer()?->isSuspended(), 403, 'This dealership’s portal access is suspended. Contact TruCars.');

        return $next($request);
    }
}
