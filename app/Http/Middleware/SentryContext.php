<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SentryContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Cek apakah user sedang login saat request ini terjadi
        if (auth()->check()) {
            $user = auth()->user();

            // Suntikkan data user ke dalam Sentry
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($user, $request): void {
                $scope->setUser([
                    'id'         => $user->id,
                    'email'      => $user->email,
                    'username'   => $user->first_name . ' ' . $user->last_name,
                    'role'       => $user->usertype,          // Mengetahui apakah dia admin/reseller/user
                    'ip_address' => $request->ip(),           // Melacak IP pengguna
                ]);

                // Opsional: Tambahkan tag khusus agar bisa di-filter di Dashboard Sentry
                $scope->setTag('membership_status', $user->is_membership ? 'VIP' : 'Reguler');
            });
        }

        return $next($request);
    }
}