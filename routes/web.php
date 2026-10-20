<?php

use App\Http\Middleware\EnsureBuyerEmailIsVerified;
use App\Http\Middleware\EnsureCommandCentreAccess;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\TreatNonCustomersAsGuests;
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
$adminDomain  = config('app.admin_domain');

/*
|--------------------------------------------------------------------------
| Consumer marketplace (public host)
|--------------------------------------------------------------------------
| Single shared `web` guard with the dealer staff (one users table, buyers
| have a null dealer_id). The auth pages deliberately carry NO auth/guest
| middleware: each page self-guards in mount() and redirects to buyer.login.
|
| /verify follows the same self-guarding pattern — it needs a signed-in but
| not-yet-confirmed buyer, so it carries no middleware and bounces guests to
| buyer.login from mount(). /garage is the one consumer surface that demands
| a confirmed email, so it runs EnsureBuyerEmailIsVerified.
*/
Route::domain($publicDomain)->middleware(TreatNonCustomersAsGuests::class)->group(function () {
    Route::livewire('/', 'pages::marketplace');

    Route::livewire('/cars/{vehicle}', 'pages::vehicle');

    Route::livewire('/cars/{vehicle}/reserve', 'pages::checkout')
        ->name('checkout');

    Route::livewire('/login', 'pages::buyer-login')->name('buyer.login');
    Route::livewire('/register', 'pages::buyer-register')->name('buyer.register');

    // Sign a buyer out. Mirrors dealer.logout on the staff host — full session
    // invalidation and token regeneration — but lands back on the marketplace
    // floor rather than a login screen, since the floor is public.
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    })->middleware('auth')->name('buyer.logout');

    Route::livewire('/verify', 'pages::verify')->name('verify');

    // Saved cars. Carries no middleware and self-guards in mount() like the
    // other consumer pages — a guest is sent to buyer.login, dealer staff back
    // to the floor. Deliberately not behind EnsureBuyerEmailIsVerified: a buyer
    // can save the moment they have an account, so they can view those saves
    // without clearing verification first.
    Route::livewire('/saved', 'pages::saved')->name('saved');

    Route::livewire('/garage', 'pages::garage')
        ->middleware(EnsureBuyerEmailIsVerified::class)
        ->name('garage');

    // Dealer campaign links. The token is the whole contract: each page resolves
    // it server-side to an active campaign and takes the dealer and destination
    // from that row — never from a query parameter or the referrer. Everything
    // under /shop/{token} is fenced to that dealer's eligible manufacturer-CPO
    // stock. The open marketplace at / is untouched and stays cross-dealer.
    Route::livewire('/shop/{token}', 'pages::campaign')
        ->where('token', '[a-z0-9]{12}')
        ->name('campaign.shop');

    Route::livewire('/shop/{token}/cars/{vehicle}', 'pages::vehicle')
        ->where('token', '[a-z0-9]{12}')
        ->name('campaign.vehicle');
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

    // Accepting an owner's invitation creates the staff account. Guests only:
    // the token is the credential, and it works once.
    Route::livewire('/invitation/{token}', 'pages::accept-invitation')
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('guest')
        ->name('dealer.invitation');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('dealer.login');
    })->middleware('auth')->name('dealer.logout');

    Route::livewire('/reservations', 'pages::reservations')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.reservations');

    // The committed-deal workspace. A separate /deals/{deal} path (not nested
    // under /reservations) so it can never collide with the lead detail route
    // below — leads and deals are different models on different keys. DealerScope
    // on Deal fences the bound model to the signed-in rooftop, so a deal from
    // another dealership 404s before the page loads.
    Route::livewire('/deals/{deal}', 'pages::deal-detail')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.deal');

    Route::livewire('/reservations/{lead}', 'pages::lead-detail')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.lead');

    Route::livewire('/fees', 'pages::fees')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.fees');

    Route::livewire('/campaigns', 'pages::campaigns')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.campaigns');
});

/*
|--------------------------------------------------------------------------
| Command Centre (admin host)
|--------------------------------------------------------------------------
| TruCars' own workspace: the owner and internal staff (support, operations).
| Its own host (ADMIN_DOMAIN, default admin.<app host>) gives it a host-only
| session apart from the marketplace and the dealer portal.
|
| The host is isolation, not authorisation. EnsureCommandCentreAccess admits
| only owner/internal-staff accounts; each page then needs its permission
| (`can:` middleware), and components re-check with Gate::authorize before
| every write. Both run again on every Livewire action.
*/
Route::domain($adminDomain)->group(function () {
    Route::livewire('/', 'pages::command-centre')
        ->middleware(EnsureCommandCentreAccess::class)
        ->name('admin.home');

    Route::livewire('/login', 'pages::login')
        ->defaults('portal', 'owner')
        ->middleware('guest')
        ->name('admin.login');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('admin.login');
    })->middleware('auth')->name('admin.logout');

    // Staff accept their invitation here; the token is the credential.
    Route::livewire('/invitation/{token}', 'pages::accept-staff-invitation')
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('guest')
        ->name('admin.invitation');

    Route::livewire('/dealers', 'pages::admin-dealers')
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.view'])
        ->name('admin.dealers');

    Route::livewire('/dealers/new', 'pages::admin-dealer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.manage'])
        ->name('admin.dealers.create');

    Route::livewire('/dealers/{dealer}', 'pages::admin-dealer')
        ->whereNumber('dealer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.view'])
        ->name('admin.dealers.edit');

    Route::livewire('/customers', 'pages::admin-customers')
        ->middleware([EnsureCommandCentreAccess::class, 'can:customers.view'])
        ->name('admin.customers');

    Route::livewire('/customers/{customer}', 'pages::admin-customer')
        ->whereNumber('customer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:customers.view'])
        ->name('admin.customers.show');

    Route::livewire('/reservations', 'pages::admin-reservations')
        ->middleware([EnsureCommandCentreAccess::class, 'can:reservations.view'])
        ->name('admin.reservations');

    Route::livewire('/reservations/{deal}', 'pages::admin-reservation')
        ->whereNumber('deal')
        ->middleware([EnsureCommandCentreAccess::class, 'can:reservations.view'])
        ->name('admin.reservations.show');

    Route::livewire('/settings', 'pages::admin-settings')
        ->middleware([EnsureCommandCentreAccess::class, 'can:settings.manage'])
        ->name('admin.settings');

    Route::livewire('/staff', 'pages::admin-staff')
        ->middleware([EnsureCommandCentreAccess::class, 'can:staff.manage'])
        ->name('admin.staff');

    Route::livewire('/audit', 'pages::admin-audit')
        ->middleware([EnsureCommandCentreAccess::class, 'can:audit.view'])
        ->name('admin.audit');
});
