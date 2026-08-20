<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class FraudDetectionService
{
    /**
     * Menganalisa tingkat risiko sebuah transaksi.
     * Mengembalikan skor 0 - 100. Semakin tinggi, semakin bahaya.
     */
    public function analyze($user, $requestIp, $receiverName, $totalAmount, $cityDest)
    {
        $score = 0;
        $flags = [];

        // ---------------------------------------------------------
        // RULE 1: Velocity Check (Frekuensi Pembelian dalam 1 Jam)
        // ---------------------------------------------------------
        $recentOrdersCount = Transaction::where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
            
        if ($recentOrdersCount >= 5) {
            $score += 60;
            $flags[] = "⚠️ Velocity Alert: Melakukan {$recentOrdersCount} pesanan berturut-turut dalam 1 jam terakhir.";
        } elseif ($recentOrdersCount >= 3) {
            $score += 30;
            $flags[] = "⚠️ High Activity: Melakukan {$recentOrdersCount} pesanan dalam 1 jam.";
        }

        // ---------------------------------------------------------
        // RULE 2: Gibberish Name Check (Nama Penerima Ngawur)
        // ---------------------------------------------------------
        $nameNoSpaces = str_replace(' ', '', strtolower($receiverName));
        
        // Cek jika ada huruf yang sama diulang 4x (contoh: "asdasdaaa", "budi bbb")
        if (preg_match('/(.)\1{3,}/', $nameNoSpaces)) {
            $score += 40;
            $flags[] = "⚠️ Gibberish Alert: Nama penerima terdeteksi sebagai ketikan acak/bot ('{$receiverName}').";
        }
        // Cek jika tidak ada satupun huruf vokal (contoh: "xzxcvbnm")
        elseif (!preg_match('/[aeiou]/', $nameNoSpaces)) {
            $score += 40;
            $flags[] = "⚠️ Suspicious Name: Nama tidak memiliki huruf vokal ('{$receiverName}').";
        }

        // ---------------------------------------------------------
        // RULE 3: Anomalous Order Amount (Pesanan Terlalu Besar untuk User Baru)
        // ---------------------------------------------------------
        $lifetimeOrders = Transaction::where('user_id', $user->id)->count();
        if ($lifetimeOrders <= 1 && $totalAmount > 5000000) {
            $score += 35;
            $flags[] = "⚠️ High Value Anomaly: Pengguna baru (0 riwayat belanja) memesan lebih dari Rp 5.000.000.";
        }

        // ---------------------------------------------------------
        // RULE 4: IP Geolocation vs Destination City (Jika di-hosting)
        // Menggunakan IP-API gratis untuk mengecek negara/kota IP
        // ---------------------------------------------------------
        if ($requestIp && $requestIp !== '127.0.0.1' && $requestIp !== '::1') {
            try {
                $response = Http::timeout(3)->get("http://ip-api.com/json/{$requestIp}");
                if ($response->successful()) {
                    $geoData = $response->json();
                    if (isset($geoData['countryCode']) && $geoData['countryCode'] !== 'ID') {
                        $score += 80; // IP Luar Negeri order barang lokal? Sangat mencurigakan!
                        $flags[] = "🔴 Foreign IP Alert: IP Address berasal dari Luar Negeri ({$geoData['country']}), tapi dikirim ke Indonesia.";
                    }
                }
            } catch (\Exception $e) {
                // Abaikan jika API timeout agar checkout tidak error
            }
        }

        // Batasi maksimal skor di angka 100
        $score = min($score, 100);

        return [
            'score' => $score,
            'flags' => $flags,
            'is_risky' => $score >= 80 // Threshold Gimmick kita!
        ];
    }
}