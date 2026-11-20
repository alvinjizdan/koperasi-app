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
        Gate::before(function ($user, string $ability) {
            // Invariant CC-03A / CC-05: Superadmin adalah administrator teknis & tata kelola,
            // BUKAN otoritas bisnis untuk dispensasi kelayakan kredit/pengecualian pembiayaan.
            if (in_array($ability, ['shm.credit_checking.override_fail', 'shm.credit_checking.bypass_completion'], true)) {
                return null; // Harus dievaluasi berdasarkan kepemilikan role/permission eksplisit (Direktur Utama)
            }

            return $user->isSuperadmin() ? true : null;
        });

        // Fallback gate for any permission check to query user's actual permissions
        Gate::after(fn ($user, string $ability) => $user->hasPermission($ability));

        foreach (array_keys(config('permissions.modules', [])) as $module) {
            $actions = array_keys(config("permissions.modules.$module.actions", []));

            foreach ($actions as $action) {
                $permission = "$module.$action";

                Gate::define($permission, fn ($user) => $user->hasPermission($permission));
            }
        }
    }
}
