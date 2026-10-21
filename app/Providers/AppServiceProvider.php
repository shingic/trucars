<?php

namespace App\Providers;

use App\Http\Middleware\EnsureBuyerEmailIsVerified;
use App\Http\Middleware\EnsureCommandCentreAccess;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\TreatNonCustomersAsGuests;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Support\Payments\DepositGateway::class, \App\Support\Payments\StripeDepositGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Livewire component actions arrive at /livewire/update, not the page's
        // route, so route middleware wouldn't run for them on its own. Marking
        // these persistent re-applies them to every action on a page that had
        // them — the role checks hold for clicks, not just page loads.
        Livewire::addPersistentMiddleware([
            EnsureCommandCentreAccess::class,
            Authorize::class, // `can:<permission>` route middleware
            EnsureUserIsStaff::class,
            EnsureBuyerEmailIsVerified::class,
            TreatNonCustomersAsGuests::class,
        ]);

        // One gate per Command Centre permission, so checks read
        // Gate::authorize('dealers.manage') / can:dealers.manage. The owner holds
        // every permission; internal staff hold what they were granted; a
        // suspended account holds nothing (see User::hasPermission).
        foreach (Permission::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        // The Dealers list is the way in to dealers and to their customers, so
        // customer support (customers.view) can open it too — but only the
        // Customers tab of each dealer, never its profile or inventory.
        Gate::define('dealers.browse', fn (User $user) => $user->hasPermission(Permission::DEALERS_VIEW) || $user->hasPermission(Permission::CUSTOMERS_VIEW));
    }
}
