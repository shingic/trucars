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
    // Visitors land on the homepage; the shop (search floor) is at /shop.
    Route::livewire('/', 'pages::home')->name('home');
    Route::livewire('/shop', 'pages::marketplace')->name('shop');

    // Search landing pages: the shop with a fixed filter at a clean URL
    // (/used-cars/honda/civic, /used-cars/body/suv, /used-cars/under-20000,
    // /used-cars/in/chilliwack-bc). App\Support\Seo\SeoCatalog decides which exist.
    Route::livewire('/used-cars/{first}/{second?}', 'pages::marketplace')
        ->where(['first' => '[a-z0-9-]+', 'second' => '[a-z0-9-]+'])
        ->name('landing');

    // One-click unsubscribe from marketing email (the link in every email, and
    // List-Unsubscribe-Post for mail apps). Signed, so it needs no sign-in.
    Route::match(['get', 'post'], '/email/unsubscribe/{user}', function (\App\Models\User $user) {
        if ($user->marketing_unsubscribed_at === null) {
            $user->forceFill(['marketing_unsubscribed_at' => now()])->save();
            \App\Models\AuditLog::record('marketing.unsubscribed', $user);
        }

        return request()->isMethod('post') ? response()->noContent() : view('marketing-unsubscribed');
    })->middleware('signed')->withoutMiddleware(TreatNonCustomersAsGuests::class)->name('marketing.unsubscribe');

    // "Unsubscribe from this type of email" — the link in each marketing email
    // and its List-Unsubscribe header. Signed, so it needs no sign-in.
    Route::match(['get', 'post'], '/email/unsubscribe/{user}/{type}', function (\App\Models\User $user, string $type) {
        abort_unless(\App\Support\Marketing\EmailTypes::exists($type), 404);

        if (! in_array($type, $user->emailTypesOff(), true)) {
            $before = $user->emailTypesOff();
            $user->setEmailType($type, false);
            $user->save();
            \App\Models\AuditLog::record('marketing.preferences_updated', $user, ['off' => $before], ['off' => $user->emailTypesOff()]);
        }

        return request()->isMethod('post') ? response()->noContent() : view('marketing-unsubscribed', [
            'typeLabel'      => \App\Support\Marketing\EmailTypes::label($type),
            'preferencesUrl' => \Illuminate\Support\Facades\URL::signedRoute('email.preferences', $user),
        ]);
    })->middleware('signed')->withoutMiddleware(TreatNonCustomersAsGuests::class)->name('marketing.unsubscribe.type');

    // Manage email preferences: one switch per type of marketing email. A
    // signed link from any marketing email or from My Garage; no sign-in.
    Route::livewire('/email/preferences/{user}', 'pages::email-preferences')
        ->middleware('signed')->withoutMiddleware(TreatNonCustomersAsGuests::class)->name('email.preferences');

    Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)->name('sitemap');
    Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /livewire/\n\nSitemap: " . route('sitemap') . "\n", 200, ['Content-Type' => 'text/plain']));
    Route::livewire('/how-it-works', 'pages::how-it-works')->name('how-it-works');

    // A dealer's storefront photo (public — it's the dealer's own branding).
    Route::get('/media/dealers/{dealer}/cover', function (\App\Models\Dealer $dealer) {
        abort_unless($dealer->cover_photo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($dealer->cover_photo_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($dealer->cover_photo_path, null, ['Cache-Control' => 'public, max-age=604800']);
    })->whereNumber('dealer')->name('dealer.cover');

    Route::livewire('/cars/{vehicle}', 'pages::vehicle');

    Route::livewire('/cars/{vehicle}/reserve', 'pages::checkout')
        ->name('checkout');

    // Back from Didit's hosted ID check: read the decision, return to checkout.
    Route::get('/cars/{vehicle}/reserve/identity', function (\App\Models\Vehicle $vehicle, \Illuminate\Http\Request $request, \App\Support\IdentityChecks $checks) {
        $verification = \App\Models\IdentityVerification::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', \App\Models\IdentityVerification::PROVIDER_DIDIT)
            ->when($request->filled('verificationSessionId'), fn ($query) => $query->where('session_id', (string) $request->query('verificationSessionId')))
            ->latest('id')
            ->first();

        if ($verification !== null) {
            try {
                $checks->refresh($verification);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Didit decision fetch failed', ['session_id' => $verification->session_id, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('checkout', $vehicle);
    })->middleware('auth')->name('identity.return');

    // Didit's ID-check status changes (signed; no session or CSRF).
    Route::post('/didit/webhook', \App\Http\Controllers\DiditWebhookController::class)
        ->withoutMiddleware(TreatNonCustomersAsGuests::class)
        ->name('didit.webhook');

    // Stripe's events for the $150 deposit (signed; no session or CSRF).
    Route::post('/stripe/webhook', \App\Http\Controllers\StripeWebhookController::class)
        ->withoutMiddleware(TreatNonCustomersAsGuests::class)
        ->name('stripe.webhook');

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

    // The committed-deal workspace, at /deals/{deal}. DealerScope
    // on Deal fences the bound model to the signed-in rooftop, so a deal from
    // another dealership 404s before the page loads.
    Route::livewire('/deals/{deal}', 'pages::deal-detail')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.deal');

    // The buyer's ID photos / Didit report for this deal (e.g. for Dealertrack).
    Route::get('/deals/{deal}/id/{part}', [\App\Http\Controllers\IdentityMediaController::class, 'forDealer'])
        ->whereIn('part', array_keys(\App\Http\Controllers\IdentityMediaController::PARTS))
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.deal.identity');


    // The dealership's own inventory — fenced to the signed-in staff member's dealer.
    Route::livewire('/inventory', 'pages::dealer-inventory')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.inventory');

    Route::livewire('/fees', 'pages::fees')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.fees');

    // The coverage plans, GAP, add-ons and accessories this dealership's buyers can flag at checkout.
    Route::livewire('/protection', 'pages::dealer-protection')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.protection');

    // Manufacturer offers to opt in to, and the dealer's own promotions.
    Route::livewire('/offers', 'pages::dealer-offers')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.offers');

    Route::livewire('/campaigns', 'pages::campaigns')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.campaigns');

    // Staff with stores at more than one dealer of a group: every store they
    // can see, side by side, and which dealer the rest of the portal shows.
    Route::livewire('/group', 'pages::dealer-group')
        ->middleware(EnsureUserIsStaff::class)
        ->name('dealer.group');

    Route::post('/switch-dealer', function (\Illuminate\Http\Request $request) {
        $dealerId = (int) $request->input('dealer_id');
        abort_unless(in_array($dealerId, $request->user()->accessibleDealerIds(), true), 403);

        $request->session()->put(\App\Models\User::PORTAL_DEALER_SESSION, $dealerId);

        return redirect()->route('dealer.reservations');
    })->middleware(EnsureUserIsStaff::class)->name('dealer.switch');
});

/*
|--------------------------------------------------------------------------
| Command Centre (admin host)
|--------------------------------------------------------------------------
| Trumo's own workspace: the owner and internal staff (support, operations).
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
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.browse'])
        ->name('admin.dealers');

    Route::livewire('/dealers/new', 'pages::admin-dealer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.manage'])
        ->name('admin.dealers.create');

    Route::livewire('/dealers/{dealer}', 'pages::admin-dealer')
        ->whereNumber('dealer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.view'])
        ->name('admin.dealers.edit');

    // A dealer's inventory: view with dealers.view; import and publish need
    // inventory.manage (checked per action in the component).
    Route::livewire('/dealers/{dealer}/inventory', 'pages::admin-dealer-inventory')
        ->whereNumber('dealer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.view'])
        ->name('admin.dealers.inventory');

    // A dealer's customers: the buyers with reservations there.
    Route::livewire('/dealers/{dealer}/customers', 'pages::admin-dealer-customers')
        ->whereNumber('dealer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:customers.view'])
        ->name('admin.dealers.customers');

    // A dealer's protection & add-ons: view with dealers.view, change with dealers.manage.
    Route::livewire('/dealers/{dealer}/protection', 'pages::admin-dealer-protection')
        ->whereNumber('dealer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:dealers.view'])
        ->name('admin.dealers.protection');




    Route::livewire('/customers', 'pages::admin-customers')
        ->middleware([EnsureCommandCentreAccess::class, 'can:customers.view'])
        ->name('admin.customers');

    Route::livewire('/customers/{customer}', 'pages::admin-customer')
        ->whereNumber('customer')
        ->middleware([EnsureCommandCentreAccess::class, 'can:customers.view'])
        ->name('admin.customers.show');

    // Shoppers who haven't paid a deposit belong to the platform: they're only
    // ever shown here, never in the dealer portal.
    // Every email the platform sends: counts, previews, pausing (marketing.view;
    // changes need settings.manage, checked in the page).
    Route::livewire('/emails', 'pages::admin-emails')
        ->middleware([EnsureCommandCentreAccess::class, 'can:marketing.view'])
        ->name('admin.emails');

    // Everyone who signed up, their saved cars and email status (marketing.view).
    Route::livewire('/shoppers', 'pages::admin-shoppers')
        ->middleware([EnsureCommandCentreAccess::class, 'can:marketing.view'])
        ->name('admin.shoppers');

    Route::livewire('/checkouts', 'pages::admin-checkouts')
        ->middleware([EnsureCommandCentreAccess::class, 'can:customers.view'])
        ->name('admin.checkouts');

    Route::livewire('/inquiries', 'pages::admin-inquiries')
        ->middleware([EnsureCommandCentreAccess::class, 'can:customers.view'])
        ->name('admin.inquiries');

    Route::livewire('/reservations', 'pages::admin-reservations')
        ->middleware([EnsureCommandCentreAccess::class, 'can:reservations.view'])
        ->name('admin.reservations');

    Route::livewire('/reservations/{deal}', 'pages::admin-reservation')
        ->whereNumber('deal')
        ->middleware([EnsureCommandCentreAccess::class, 'can:reservations.view'])
        ->name('admin.reservations.show');

    Route::get('/reservations/{deal}/id/{part}', [\App\Http\Controllers\IdentityMediaController::class, 'forCommandCentre'])
        ->whereNumber('deal')
        ->whereIn('part', array_keys(\App\Http\Controllers\IdentityMediaController::PARTS))
        ->middleware([EnsureCommandCentreAccess::class, 'can:reservations.view'])
        ->name('admin.reservations.identity');

    Route::livewire('/settings', 'pages::admin-settings')
        ->middleware([EnsureCommandCentreAccess::class, 'can:settings.manage'])
        ->name('admin.settings');

    // Brands: every dealer belongs to one; each brand's CPO checklist and
    // offers apply to all of its dealers (settings.manage).
    Route::middleware([EnsureCommandCentreAccess::class, 'can:settings.manage'])->group(function () {
        // The default protection & add-ons list every dealership offers at checkout.
        Route::livewire('/settings/protection', 'pages::admin-protection')->name('admin.settings.protection');
        Route::livewire('/settings/brands', 'pages::admin-brands')->name('admin.brands');
        Route::livewire('/settings/brands/{brand}', 'pages::admin-brand')->whereNumber('brand')->name('admin.brands.show');
        Route::livewire('/settings/brands/{brand}/checklist', 'pages::admin-brand-checklist')->whereNumber('brand')->name('admin.brands.checklist');
        Route::livewire('/settings/brands/{brand}/benefits', 'pages::admin-brand-benefits')->whereNumber('brand')->name('admin.brands.benefits');
        Route::livewire('/settings/brands/{brand}/offers', 'pages::admin-brand-offers')->whereNumber('brand')->name('admin.brands.offers');
        Route::livewire('/settings/brands/{brand}/offers/new', 'pages::admin-offer')->whereNumber('brand')->name('admin.brands.offers.create');
        Route::livewire('/settings/brands/{brand}/offers/{offer}', 'pages::admin-offer')->whereNumber(['brand', 'offer'])->name('admin.brands.offers.edit');
    });
    Route::redirect('/settings/brand-checklists', '/settings/brands');


    Route::livewire('/staff', 'pages::admin-staff')
        ->middleware([EnsureCommandCentreAccess::class, 'can:staff.manage'])
        ->name('admin.staff');

    Route::livewire('/audit', 'pages::admin-audit')
        ->middleware([EnsureCommandCentreAccess::class, 'can:audit.view'])
        ->name('admin.audit');
});
