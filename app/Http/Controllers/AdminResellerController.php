<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResellerApplication;
use Illuminate\Support\Facades\DB;

class AdminResellerController extends Controller
{
    // Mengambil daftar pendaftar untuk dirender di tabel Vue Admin
    public function index()
    {
        $applications = ResellerApplication::with('user')
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
            ->latest()
            ->get();

        return response()->json(['data' => $applications]);
    }

    // Eksekusi Persetujuan
    public function approve($id)
    {
        $application = ResellerApplication::findOrFail($id);

        if ($application->status !== 'pending') {
            return response()->json(['message' => 'Pendaftaran ini sudah diproses.'], 400);
        }

        try {
            DB::transaction(function () use ($application) {
                // 1. Ubah status form
                $application->update(['status' => 'approved']);

                // 2. Ubah wujud User menjadi Reseller
                $application->user->update([
                    'usertype' => 'reseller'
                ]);
            });

            // (Opsional) Kelak Anda bisa menaruh Mail::queue() di sini
            // untuk mengirimkan ucapan "Selamat Bergabung sebagai Partner Gycora"

            return response()->json([
                'status' => 'success',
                'message' => 'Aplikasi disetujui! Pengguna kini mendapatkan akses harga Business Partner.'
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memproses persetujuan: ' . $e->getMessage()], 500);
        }
    }

    // Eksekusi Penolakan
    public function reject($id)
    {
        $application = ResellerApplication::findOrFail($id);

        if ($application->status !== 'pending') {
            return response()->json(['message' => 'Pendaftaran ini sudah diproses sebelumnya.'], 400);
        }

        // Cukup ubah status form, tidak perlu mengubah wujud User
        $application->update(['status' => 'rejected']);

        return response()->json([
            'status' => 'success',
            'message' => 'Aplikasi Business Partner telah ditolak.'
        ]);
    }
}
