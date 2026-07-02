<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Pastikan user sudah login
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Cek apakah usertype user saat ini ada di dalam array roles yang diizinkan
        if (!in_array($request->user()->usertype, $roles)) {
            return response()->json([
                'message' => 'Forbidden. Anda tidak memiliki akses ke resource ini.'
            ], 403);
        }

        return $next($request);
    }
}
