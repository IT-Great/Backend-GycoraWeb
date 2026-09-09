<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next)
    {
        // Mengecek apakah sistem diletakkan dalam mode maintenance
        if (Cache::get('maintenance_mode', false)) {
            $whitelistedIp = Cache::get('maintenance_whitelist_ip');

            // Izinkan jika yang mengakses adalah IP Developer (Admin yang menyalakan tombol)
            // Atau berikan akses masuk ke endpoint login/maintenance agar admin tidak terkunci di luar
            if ($request->ip() !== $whitelistedIp && !$request->is('api/admin/login*')) {
                return response()->json([
                    'message' => 'Sistem sedang dalam perbaikan berkala. Harap kembali beberapa saat lagi.',
                    'is_maintenance' => true
                ], 503);
            }
        }

        return $next($request);
    }
}
