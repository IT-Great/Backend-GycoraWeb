<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function getCohortAnalysis()
    {
        // 🌟 SQL MAGIC: Common Table Expressions (CTE) 🌟
        // 1. Cari bulan transaksi PERTAMA dari tiap user (Bulan Kohor)
        // 2. Hitung selisih bulan dari setiap transaksi berikutnya terhadap Bulan Kohor
        $query = "
            WITH FirstPurchases AS (
                SELECT user_id, DATE_FORMAT(MIN(created_at), '%Y-%m-01') as cohort_month
                FROM transactions
                WHERE status IN ('paid', 'processing', 'shipped', 'completed')
                GROUP BY user_id
            ),
            CohortActivities AS (
                SELECT
                    t.user_id,
                    fp.cohort_month,
                    TIMESTAMPDIFF(MONTH, fp.cohort_month, DATE_FORMAT(t.created_at, '%Y-%m-01')) as month_offset
                FROM transactions t
                JOIN FirstPurchases fp ON t.user_id = fp.user_id
                WHERE t.status IN ('paid', 'processing', 'shipped', 'completed')
            )
            SELECT
                cohort_month,
                month_offset,
                COUNT(DISTINCT user_id) as users_count
            FROM CohortActivities
            GROUP BY cohort_month, month_offset
            ORDER BY cohort_month, month_offset
        ";

        $results = DB::select($query);

        $cohorts = [];
        $maxOffset = 0;

        // Mengelompokkan data berdasarkan Bulan Kohor
        foreach ($results as $row) {
            $month = Carbon::parse($row->cohort_month)->translatedFormat('M Y'); // cth: "Jan 2026"
            $offset = (int) $row->month_offset;
            $count = (int) $row->users_count;

            if (!isset($cohorts[$month])) {
                $cohorts[$month] = [
                    'cohort_month' => $month,
                    'total_users' => 0,
                    'retention' => []
                ];
            }

            if ($offset === 0) {
                $cohorts[$month]['total_users'] = $count; // Jumlah user unik di bulan pertama
            }

            if ($offset > $maxOffset) {
                $maxOffset = $offset;
            }

            $cohorts[$month]['retention'][$offset] = $count;
        }

        // Kalkulasi Persentase Retensi untuk diubah menjadi Heatmap di Frontend
        $finalData = [];
        foreach ($cohorts as $cohort) {
            $baseUsers = $cohort['total_users'];
            $percentages = [];

            for ($i = 0; $i <= $maxOffset; $i++) {
                if (isset($cohort['retention'][$i]) && $baseUsers > 0) {
                    $percentages[$i] = round(($cohort['retention'][$i] / $baseUsers) * 100, 1);
                } else {
                    $percentages[$i] = null; // null = belum ada data (waktu belum berlalu)
                }
            }

            $finalData[] = [
                'cohort_month' => $cohort['cohort_month'],
                'total_users' => $baseUsers,
                'retention_rates' => $percentages
            ];
        }

        return response()->json([
            'max_months' => $maxOffset,
            'data' => array_values($finalData)
        ]);
    }
}
