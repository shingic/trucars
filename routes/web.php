<?php

use App\Http\Middleware\EnsureUserIsStaff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hosts
|--------------------------------------------------------------------------
| The consumer marketplace and the dealer console live on separate hosts.
| The public host is taken from APP_URL; the staff host from STAFF_DOMAIN
| (config/app.php → 'staff_domain', defaulting to dealer.<app host>).
|
| Splitting the two surfaces across hosts keeps their session cookies
| host-isolated, so a signed-in buyer and a signed-in staff member never
| share or clobber each other's auth — which also retires the old worry
| about consumer and staff login redirects colliding on one domain.
*/
$publicDomain = parse_url((string) config('app.url'), PHP_URL_HOST);
$staffDomain  = config('app.staff_domain');

/*
|--------------------------------------------------------------------------
| Consumer marketplace (public host)
|--------------------------------------------------------------------------
| Single shared `web` guard with the dealer staff (one users table, buyers
| have a null dealer_id). The auth pages deliberately carry NO auth/guest
| middleware: each page self-guards in mount() and redirects to buyer.login.
*/
Route::domain($publicDomain)->group(function () {
    Route::livewire('/', 'pages::marketplace');

    Route::livewire('/cars/{vehicle}', 'pages::vehicle');

    Route::livewire('/cars/{vehicle}/reserve', 'pages::checkout')
        ->name('checkout');

    Route::livewire('/login', 'pages::buyer-login')->name('buyer.login');
    Route::livewire('/register', 'pages::buyer-register')->name('buyer.register');

    Route::livewire('/garage', 'pages::garage')
        ->name('garage');
});

/*
|--------------------------------------------------------------------------
| Dealer console (staff host)
|--------------------------------------------------------------------------
| Served only on the staff subdomain, so the paths drop the now-redundant
| /dealer prefix (e.g. dealer.trucars.test/reservations). Route names stay
| dealer.* — every route() call, redirect, and notification link regenerates
| the correct staff-host URL automatically.
|
| Gated by EnsureUserIsStaff (not plain `auth`): one users table backs both
| surfaces, so a signed-in buyer is an authenticated user with dealer_id = null.
| The gate refuses buyers and bounces guests to dealer.login. Only `login`
| (guest) and `logout` (any signed-in session) sit outside it.
*/
Route::domain($staffDomain)->group(function () {
    // Bare staff host → the console. The staff gate runs first, so a guest is
    // sent to login and a signed-in buyer is refused before the redirect ever
    // fires; only a real staff member lands on /reservations.
    Route::redirect('/', '/reservations')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.home');

    Route::livewire('/login', 'pages::login')
        ->middleware('guest')
        ->name('dealer.login');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('dealer.login');
    })->middleware('auth')->name('dealer.logout');

    Route::livewire('/reservations', 'pages::reservations')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.reservations');

    Route::livewire('/reservations/{lead}', 'pages::lead-detail')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.lead');

    Route::livewire('/fees', 'pages::fees')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.fees');
});
