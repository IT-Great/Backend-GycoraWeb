<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PredictiveInventoryController extends Controller
{
    public function getPredictiveStock()
    {
        $days = 7;
        $startDate = Carbon::now('Asia/Jakarta')->subDays($days);

        // Agregasi SQL Tingkat Lanjut: Join tabel produk dengan riwayat transaksi yang sukses
        // Catatan: Sesuaikan nama tabel 'transactions' dan 'transaction_items' dengan skema Anda jika berbeda
        $products = DB::table('products')
            ->leftJoin('transaction_items', 'products.id', '=', 'transaction_items.product_id')
            ->leftJoin('transactions', function($join) use ($startDate) {
                $join->on('transaction_items.transaction_id', '=', 'transactions.id')
                     // Hanya hitung pesanan yang sudah dibayar/selesai
                     ->whereIn('transactions.status', ['paid', 'processing', 'shipped', 'completed'])
                     ->where('transactions.created_at', '>=', $startDate);
            })
            ->select(
                'products.id',
                'products.name',
                'products.image_url',
                'products.stock',
                DB::raw('COALESCE(SUM(transaction_items.quantity), 0) as total_sold_7d')
            )
            ->where('products.is_active', true) // Abaikan produk yang sudah nonaktif
            ->groupBy('products.id', 'products.name', 'products.image_url', 'products.stock')
            ->get();

        $inventoryData = $products->map(function($item) use ($days) {
            $velocity = $item->total_sold_7d / $days; // Rata-rata barang terjual per hari

            if ($item->stock <= 0) {
                $daysRemaining = 0;
            } else {
                // Jika velocity > 0, hitung hari. Jika 0, set 999 (stok stagnan/aman panjang)
                $daysRemaining = $velocity > 0 ? floor($item->stock / $velocity) : 999;
            }

            // Penentuan Warna Status
            $statusLabel = 'Aman';
            $statusColor = 'green';

            if ($daysRemaining <= 0) {
                $statusLabel = 'Habis!';
                $statusColor = 'red';
            } elseif ($daysRemaining <= 3) {
                $statusLabel = 'Kritis (< 3 Hari)';
                $statusColor = 'red';
            } elseif ($daysRemaining <= 7) {
                $statusLabel = 'Menipis (< 7 Hari)';
                $statusColor = 'yellow';
            } elseif ($velocity == 0) {
                $statusLabel = 'Stagnan (0 Penjualan)';
                $statusColor = 'gray';
            }

            return [
                'id' => $item->id,
                'name' => $item->name,
                'image_url' => $item->image_url,
                'current_stock' => $item->stock,
                'sold_last_7_days' => $item->total_sold_7d,
                'velocity_per_day' => round($velocity, 2),
                'estimated_days_remaining' => $daysRemaining,
                'status_label' => $statusLabel,
                'status_color' => $statusColor
            ];
        });

        // Urutkan dari yang paling kritis (hari paling sedikit) ke yang paling aman
        $inventoryData = $inventoryData->sortBy('estimated_days_remaining')->values();

        return response()->json([
            'analyzed_days' => $days,
            'data' => $inventoryData
        ]);
    }
}
