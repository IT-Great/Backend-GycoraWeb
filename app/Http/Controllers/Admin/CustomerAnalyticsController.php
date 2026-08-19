<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CustomerAnalyticsController extends Controller
{
    public function getRfmSegmentation()
    {
        // 1. Ambil data mentah agregasi transaksi per user
        $stats = DB::table('users')
            ->join('transactions', 'users.id', '=', 'transactions.user_id')
            ->whereIn('transactions.status', ['paid', 'processing', 'shipped', 'completed'])
            ->selectRaw('
                users.id, 
                users.first_name, 
                users.last_name, 
                users.email, 
                DATEDIFF(NOW(), MAX(transactions.created_at)) as recency_days,
                COUNT(transactions.id) as frequency_count,
                SUM(transactions.gross_amount) as monetary_total
            ')
            ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.email')
            ->get();

        $total = $stats->count();
        if ($total == 0) {
            return response()->json([]);
        }

        // 🌟 2. ALGORITMA KUARTIL (Scoring 1-4) 🌟
        $chunk = $total / 4;

        // A. Hitung Skor R (Recency) -> Semakin kecil harinya, skor semakin tinggi (4)
        $stats = $stats->sortByDesc('recency_days')->values();
        $stats->transform(function($item, $key) use ($chunk) {
            $item->r_score = ceil(($key + 1) / $chunk);
            return $item;
        });

        // B. Hitung Skor F (Frequency) -> Semakin banyak transaksinya, skor semakin tinggi (4)
        $stats = $stats->sortBy('frequency_count')->values();
        $stats->transform(function($item, $key) use ($chunk) {
            $item->f_score = ceil(($key + 1) / $chunk);
            return $item;
        });

        // C. Hitung Skor M (Monetary) -> Semakin besar belanjanya, skor semakin tinggi (4)
        $stats = $stats->sortBy('monetary_total')->values();
        $stats->transform(function($item, $key) use ($chunk) {
            $item->m_score = ceil(($key + 1) / $chunk);
            return $item;
        });

        // 🌟 3. PROSES PELABELAN SEGMENTASI (RFM Matrix) 🌟
        $stats->transform(function($item) {
            $r = $item->r_score;
            $f = $item->f_score;
            $m = $item->m_score;

            // Logika Bisnis: Menentukan Segmentasi
            if ($r == 4 && $f >= 3 && $m >= 3) {
                $segment = 'Champions 🏆';
                $color = 'bg-yellow-100 text-yellow-800';
            } elseif ($r >= 3 && $f >= 3) {
                $segment = 'Loyal Customers 💎';
                $color = 'bg-blue-100 text-blue-800';
            } elseif ($r >= 3 && $f <= 2) {
                $segment = 'New / Promising 🌟';
                $color = 'bg-emerald-100 text-emerald-800';
            } elseif ($r == 2 && $f >= 2) {
                $segment = 'Need Attention ⚠️';
                $color = 'bg-orange-100 text-orange-800';
            } elseif ($r <= 2 && $f >= 3) {
                $segment = 'At Risk 🆘';
                $color = 'bg-red-100 text-red-800';
            } elseif ($r <= 2 && $f <= 2) {
                $segment = 'Hibernating 💤';
                $color = 'bg-gray-200 text-gray-700';
            } else {
                $segment = 'Regular 👤';
                $color = 'bg-gray-100 text-gray-800';
            }

            $item->segment = $segment;
            $item->badge_color = $color;
            $item->rfm_score = "{$r}-{$f}-{$m}";
            return $item;
        });

        // Kembalikan ke urutan default (Champions di atas)
        $finalData = $stats->sortByDesc(function($item) {
            return $item->r_score + $item->f_score + $item->m_score;
        })->values();

        return response()->json($finalData);
    }
}