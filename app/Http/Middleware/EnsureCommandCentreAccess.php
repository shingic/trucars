<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCommandCentreAccess
{
    /**
     * The Command Centre (admin host) is for TruCars' owner and internal staff
     * only — never customers or dealer users, and never a suspended account.
     * What each person may do inside it is decided per page and per action by
     * permission gates (`can:` route middleware, Gate::authorize in components).
     *
     * Runs on page loads and, as Livewire persistent middleware, on every
     * component action. The separate host isolates the session; this is the
     * security.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('admin.login');
        }

        abort_unless($user->canUseCommandCentre(), 403, 'This area is for TruCars staff.');

        return $next($request);
    }
}
