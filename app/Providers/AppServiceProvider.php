<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The reconciliation dashboard (architecture §14) reads across
        // Person, Unit, and PersonUnitRelationship at once — no single
        // model backs the screen, so this is a Gate rather than a Policy
        // method on any one of them. Same Superadmin-or-Admin check every
        // other admin screen's Policy already uses.
        Gate::define('view-reconciliation-dashboard', fn (User $user) => $user->isSuperadmin() || $user->isAdmin());

        // Site branding (Phase 16) — one row of settings, not a model an
        // admin works with, so a Gate like the dashboard's. Superadmin only.
        Gate::define('manage-site-settings', fn (User $user) => $user->isSuperadmin());
    }
}
