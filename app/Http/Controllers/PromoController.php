<?php

// namespace App\Http\Controllers;

// use Illuminate\Http\Request;
// use App\Models\PromoClaim;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Facades\Log;
// use App\Mail\PromoCodeMail;

// class PromoController extends Controller
// {
// Dipanggil dari HomePage (Pop-up)
// public function claim(Request $request)
// {
//     $request->validate(['email' => 'required|email']);
//     $code = 'SOLHERBARU'; // Hardcoded kode promo kampanye saat ini

//     $exists = PromoClaim::where('email', $request->email)->where('promo_code', $code)->first();
//     if ($exists) {
//         return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//     }

//     PromoClaim::create([
//         'email' => $request->email,
//         'promo_code' => $code,
//         'discount_value' => 25000 // Nilai diskon Rp 25.000
//     ]);

//     return response()->json([
//         'message' => 'Promo berhasil diklaim!',
//         'promo_code' => $code
//     ]);
// }

// // Dipanggil dari PaymentPage (Saat Apply Promo)
// public function verify(Request $request)
// {
//     $request->validate(['promo_code' => 'required|string']);
//     $user = Auth::user();

//     // Pastikan email user yang sedang login SAMA dengan email yang didaftarkan di pop-up
//     $claim = PromoClaim::where('email', $user->email)
//         ->where('promo_code', strtoupper($request->promo_code))
//         ->first();

//     if (!$claim) {
//         return response()->json(['message' => 'Kode promo tidak ditemukan untuk alamat email Anda.'], 404);
//     }
//     if ($claim->is_used) {
//         return response()->json(['message' => 'Kode promo ini sudah Anda gunakan sebelumnya.'], 400);
//     }

//     return response()->json([
//         'message' => 'Kode promo valid!',
//         'discount_value' => $claim->discount_value
//     ]);
// }

// Dipanggil dari HomePage (Pop-up)
// public function claim(Request $request)
// {
//     $request->validate(['email' => 'required|email']);
//     $code = 'SOLHERBARU';
//     $discountValue = 25000;

//     $exists = PromoClaim::where('email', $request->email)->where('promo_code', $code)->first();
//     if ($exists) {
//         return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//     }

//     // 1. Coba kirim email TERLEBIH DAHULU sebelum menyimpan ke database
//     try {
//         Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue));
//     } catch (\Exception $e) {
//         Log::error('Failed to send promo email to ' . $request->email . ': ' . $e->getMessage());
//         return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//     }

//     // 2. Jika email sukses terkirim, baru catat di database
//     PromoClaim::create([
//         'email' => $request->email,
//         'promo_code' => $code,
//         'discount_value' => $discountValue
//     ]);

//     return response()->json([
//         'message' => 'Promo berhasil diklaim!',
//         'promo_code' => $code
//     ]);
// }

// // Dipanggil dari PaymentPage (Saat Apply Promo)
// public function verify(Request $request)
// {
//     $request->validate(['promo_code' => 'required|string']);
//     $user = Auth::user();

//     // Pastikan email user yang sedang login SAMA dengan email yang didaftarkan di pop-up
//     $claim = PromoClaim::where('email', $user->email)
//         ->where('promo_code', strtoupper($request->promo_code))
//         ->first();

//     if (!$claim) {
//         return response()->json(['message' => 'Kode promo tidak ditemukan untuk alamat email Anda.'], 404);
//     }
//     if ($claim->is_used) {
//         return response()->json(['message' => 'Kode promo ini sudah Anda gunakan sebelumnya.'], 400);
//     }

//     return response()->json([
//         'message' => 'Kode promo valid!',
//         'discount_value' => $claim->discount_value
//     ]);
// }

// namespace App\Http\Controllers;

// use App\Mail\PromoCodeMail;
// use App\Models\PromoClaim;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Str; // <--- [BARU] Wajib di-import

// class PromoController extends Controller
// {
//     // Dipanggil dari HomePage (Pop-up)
//     public function claim(Request $request)
//     {
//         $request->validate(['email' => 'required|email']);

//         // ====================================================================
//         // [PERBAIKAN KRUSIAL] Ubah nominal diskon sesuai janji di UI (250.000)
//         // ====================================================================
//         $discountValue = 250000;

//         // [PERBAIKAN] Cek berdasarkan email saja, bukan kode promo statis
//         $exists = PromoClaim::where('email', $request->email)->first();
//         if ($exists) {
//             return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//         }

//         // Generate Kode Promo Acak (Contoh: GYCORA-A9F8B2)
//         $code = 'GYCORA-'.strtoupper(Str::random(6));

//         // 1. Coba kirim email TERLEBIH DAHULU sebelum menyimpan ke database
//         try {
//             Mail::to($request->email)->send(new \App\Mail\PromoCodeMail($code, $discountValue));
//         } catch (\Exception $e) {
//             Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());

//             return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//         }

//         // 2. Jika email sukses terkirim, baru catat di database
//         PromoClaim::create([
//             'email' => $request->email,
//             'promo_code' => $code,
//             'discount_value' => $discountValue,
//         ]);

//         return response()->json([
//             'message' => 'Promo berhasil diklaim!',
//             'promo_code' => $code,
//         ]);
//     }

//     // Dipanggil dari PaymentPage (Saat Apply Promo)
//     public function verify(Request $request)
//     {
//         $request->validate(['promo_code' => 'required|string']);
//         $user = Auth::user();

//         // Pastikan email user yang sedang login SAMA dengan email yang didaftarkan di pop-up
//         $claim = PromoClaim::where('email', $user->email)
//             ->where('promo_code', strtoupper($request->promo_code))
//             ->first();

//         // [PERBAIKAN] Berikan pesan error spesifik dalam bahasa Inggris untuk Frontend
//         if (! $claim) {
//             return response()->json(['message' => 'Invalid promo code for this email address.'], 404);
//         }
//         if ($claim->is_used) {
//             return response()->json(['message' => 'This promo code has already been used.'], 400);
//         }

//         return response()->json([
//             'message' => 'Promo applied successfully!',
//             'discount_value' => $claim->discount_value,
//         ]);
//     }
// }

// namespace App\Http\Controllers;

// use App\Mail\PromoCodeMail;
// use App\Models\PromoClaim;
// use App\Models\PromoCode; // [BARU] Tambahkan model PromoCode
// use Carbon\Carbon;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Str; // [BARU] Untuk cek expired date

// class PromoController extends Controller
// {
//     public function claim(Request $request)
//     {
//         // ... (Fungsi claim untuk Pop-up tetap SAMA PERSIS seperti sebelumnya) ...
//         $request->validate(['email' => 'required|email']);
//         $discountValue = 10;

//         $exists = PromoClaim::where('email', $request->email)->first();
//         if ($exists) {
//             return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//         }

//         $code = 'GYCORA-'.strtoupper(Str::random(6));

//         try {
//             Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue));
//         } catch (\Exception $e) {
//             Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());

//             return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//         }

//         PromoClaim::create([
//             'email' => $request->email,
//             'promo_code' => $code,
//             'discount_value' => $discountValue,
//         ]);

//         return response()->json([
//             'message' => 'Promo berhasil diklaim!',
//             'promo_code' => $code,
//         ]);
//     }

//     // =========================================================================
//     // [PERBAIKAN] FUNGSI VERIFY BISA MENGENALI 2 JENIS PROMO
//     // =========================================================================
//     public function verify(Request $request)
//     {
//         $request->validate(['promo_code' => 'required|string']);
//         $user = Auth::user();
//         $inputCode = strtoupper($request->promo_code);

//         // SKENARIO 1: Cek apakah ini Promo Claim (dari Email Subscribe)
//         $claim = PromoClaim::where('email', $user->email)
//             ->where('promo_code', $inputCode)
//             ->first();

//         if ($claim) {
//             if ($claim->is_used) {
//                 return response()->json(['message' => 'This subscriber promo code has already been used.'], 400);
//             }

//             return response()->json([
//                 'message' => 'Subscriber Promo applied successfully!',
//                 'discount_value' => $claim->discount_value,
//                 'promo_type' => 'claim',
//             ]);
//         }

//         // SKENARIO 2: Cek apakah ini Promo Code Global (Voucher dari Bos)
//         $voucher = PromoCode::where('code', $inputCode)->first();

//         if ($voucher) {
//             // Cek Expired Date
//             if ($voucher->expires_at && Carbon::now()->greaterThan($voucher->expires_at)) {
//                 return response()->json(['message' => 'This voucher code has expired.'], 400);
//             }
//             // Cek Limit Penggunaan
//             if ($voucher->times_used >= $voucher->max_uses) {
//                 return response()->json(['message' => 'This voucher code has reached its usage limit.'], 400);
//             }

//             return response()->json([
//                 'message' => 'Voucher Promo applied successfully!',
//                 'discount_value' => $voucher->discount_value,
//                 'promo_type' => 'voucher',
//             ]);
//         }

//         // JIKA KEDUANYA TIDAK DITEMUKAN
//         return response()->json(['message' => 'Invalid promo code.'], 404);
//     }
// }

namespace App\Http\Controllers;

use App\Mail\PromoCodeMail;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
}
