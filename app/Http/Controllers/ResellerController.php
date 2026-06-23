<?php

namespace App\Http\Controllers;

use App\Models\ResellerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResellerController extends Controller
{
    public function apply(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Harap login terlebih dahulu.'], 401);
        }

        // 1. Cek apakah sudah jadi reseller
        if ($user->usertype === 'reseller') {
            return response()->json(['message' => 'Anda sudah terdaftar sebagai Business Partner aktif.'], 400);
        }

        // 2. Cek apakah ada antrean yang masih pending
        $existingApp = ResellerApplication::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existingApp) {
            return response()->json(['message' => 'Aplikasi kemitraan Anda sedang dalam tahap peninjauan tim kami.'], 400);
        }

        // 3. Validasi Form B2B
        $request->validate([
            'business_name' => 'required|string|max:255',
            'sales_platform' => 'required|string|max:255',
            'monthly_capacity' => 'required|string|max:50',
            'additional_notes' => 'nullable|string|max:1000',
        ]);

        // 4. Simpan ke database
        ResellerApplication::create([
            'user_id' => $user->id,
            'business_name' => $request->business_name,
            'sales_platform' => $request->sales_platform,
            'monthly_capacity' => $request->monthly_capacity,
            'additional_notes' => $request->additional_notes,
            'status' => 'pending'
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pendaftaran Business Partner berhasil dikirim!'
        ]);
    }

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
