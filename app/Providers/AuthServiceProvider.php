<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Daftarkan seluruh permission pada registry sebagai Gate.
     * Pengguna dengan role Superadmin memperoleh akses ke semua Gate.
     */
    public function boot(): void
    {
        Gate::before(fn ($user, string $ability) => $user->isSuperadmin() ? true : null);

        foreach (array_keys(config('permissions.modules', [])) as $module) {
            $actions = array_keys(config("permissions.modules.$module.actions", []));

            foreach ($actions as $action) {
                $permission = "$module.$action";

                Gate::define($permission, fn ($user) => $user->hasPermission($permission));
            }
        }
    }
}
