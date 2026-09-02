<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        // Karena kita belum membuat migrasi tabel notifications khusus,
        // kita menggunakan mock-up data statis yang digabung dengan pesanan terbaru.
        // Jika Abang sudah punya tabel `notifications`, tinggal ganti kodenya menjadi:
        // $notifs = DB::table('notifications')->where('user_id', $user->id)->orderBy('created_at', 'desc')->get();

        $transactions = DB::table('transactions')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $mockNotifications = [];

        // Buat 1 notifikasi selamat datang
        $mockNotifications[] = [
            'id' => 9999,
            'title' => 'Selamat Datang di Gycora!',
            'message' => 'Lengkapi profil Anda dan dapatkan promo eksklusif.',
            'is_read' => false,
            'link' => '/profile'
        ];

        // Buat notifikasi dari status pesanan terakhir
        foreach ($transactions as $trx) {
            $statusStr = '';
            if ($trx->status === 'completed') $statusStr = 'Telah Selesai';
            elseif ($trx->status === 'processing') $statusStr = 'Sedang Diproses';
            else $statusStr = 'Menunggu Pembayaran';

            $mockNotifications[] = [
                'id' => $trx->id,
                'title' => 'Update Pesanan ' . $trx->order_id,
                'message' => 'Status pesanan Anda saat ini: ' . $statusStr,
                'is_read' => false,
                'link' => '/orders'
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $mockNotifications
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        // Logika untuk menandai is_read = true di tabel notifications
        // DB::table('notifications')->where('id', $id)->where('user_id', $request->user()->id)->update(['is_read' => true]);

        return response()->json(['status' => 'success']);
    }
}
