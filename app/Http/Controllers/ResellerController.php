<?php

namespace App\Http\Controllers;

use App\Models\ResellerApplication;
use Illuminate\Http\Request;

class ResellerController extends Controller
{
    public function apply(Request $request)
    {
        $user = $request->user();

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
}
