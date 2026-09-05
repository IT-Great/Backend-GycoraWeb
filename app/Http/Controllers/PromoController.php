<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\PromoCode;
use App\Models\PromoClaim;
use App\Mail\PromoCodeMail;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class PromoController extends Controller
{
    public function claim(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $discountValue = 10;

        // Cek apakah user sudah punya promo yang BELUM EXPIRED dan BELUM DIPAKAI
        $exists = PromoClaim::where('email', $request->email)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($exists) {
            return response()->json(['message' => 'Anda sudah memiliki kode promo yang masih aktif. Silakan cek email Anda.'], 400);
        }

        $code = 'GYCORA-'.strtoupper(Str::random(6));

        try {
            Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue));
        } catch (\Exception $e) {
            Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());
            return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid.'], 500);
        }

        // Simpan promo dengan batas waktu 24 JAM dari sekarang
        PromoClaim::create([
            'email' => $request->email,
            'promo_code' => $code,
            'discount_value' => $discountValue,
            'is_used' => false,
            'expires_at' => Carbon::now()->addHours(24), // <-- LOGIKA A: AKTIF 24 JAM
        ]);

        return response()->json([
            'message' => 'Promo berhasil diklaim! Cek email Anda.',
            'promo_code' => $code,
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate(['promo_code' => 'required|string']);
        $user = Auth::user();
        $inputCode = strtoupper($request->promo_code);

        // SKENARIO 1: Promo Claim (dari Email Subscribe)
        $claim = PromoClaim::where('email', $user->email)
            ->where('promo_code', $inputCode)
            ->first();

        if ($claim) {
            // <-- LOGIKA C: CEK JIKA SUDAH DIPAKAI (REDEEMED)
            if ($claim->is_used) {
                return response()->json(['message' => 'Kode promo ini sudah pernah Anda gunakan sebelumnya.'], 400);
            }

            // <-- LOGIKA B: CEK JIKA SUDAH LEWAT 24 JAM (EXPIRED)
            if ($claim->expires_at && Carbon::now()->greaterThan($claim->expires_at)) {
                return response()->json(['message' => 'Kode promo ini sudah kedaluwarsa (lewat dari 24 jam).'], 400);
            }

            return response()->json([
                'message' => 'Promo berhasil diterapkan!',
                'discount_value' => $claim->discount_value,
                'promo_type' => 'claim',
            ]);
        }

        // SKENARIO 2: Promo Code Global (Voucher Bebas)
        $voucher = PromoCode::where('code', $inputCode)->first();

        if ($voucher) {
            if ($voucher->expires_at && Carbon::now()->greaterThan($voucher->expires_at)) {
                return response()->json(['message' => 'Voucher ini sudah kedaluwarsa.'], 400);
            }
            if ($voucher->times_used >= $voucher->max_uses) {
                return response()->json(['message' => 'Kuota penggunaan voucher ini sudah habis.'], 400);
            }

            return response()->json([
                'message' => 'Voucher berhasil diterapkan!',
                'discount_value' => $voucher->discount_value,
                'promo_type' => 'voucher',
            ]);
        }

        return response()->json(['message' => 'Kode promo tidak valid atau tidak ditemukan.'], 404);
    }

    public function getActiveCampaigns()
    {
        $now = Carbon::now('Asia/Jakarta');
        $promos = \App\Models\DynamicPromo::where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->get(['name', 'banner_badge', 'rules', 'end_date']);

        return response()->json($promos);
    }
}
