<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware otorisasi berbasis permission {module}.{action}.
 * Superadmin lolos melalui Gate::before.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($request->user() === null) {
            abort(401);
        }

        foreach ($permissions as $permission) {
            if (! Gate::forUser($request->user())->allows($permission)) {
                abort(403, 'Anda tidak memiliki izin untuk mengakses fitur ini.');
            }
        }

        return $next($request);
    }
}
