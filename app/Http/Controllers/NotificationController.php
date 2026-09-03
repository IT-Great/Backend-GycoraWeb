<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller {

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user)
            return response()->json(['message' => 'Unauthorized'], 401);

        // Auto-Sync: Memasukkan pesanan ke dalam tabel notifikasi jika belum ada
        $transactions = DB::table('transactions')
            ->where('user_id', $user->id)
            ->get();

        foreach($transactions as $trx) {
            $statusStr = '';
            if ($trx->status === 'completed')
                $statusStr = 'Telah Selesai';
            else if ($trx->status === 'processing')
                $statusStr = 'Sedang Diproses';
            else
                $statusStr = 'Menunggu Pembayaran';

            $title = 'Update Pesanan ' . $trx->order_id;
            $msg = 'Status pesanan Anda saat ini: ' . $statusStr;

            $exists = DB::table('notifications')
                ->where('user_id', $user->id)
                ->where('title', $title)
                ->where('message', $msg)
                ->exists();

            if (!$exists) {
                DB::table('notifications')->insert([
                    'user_id' => $user->id,
                    'title' => $title,
                    'message' => $msg,
                    'link' => '/orders',
                    'is_read' => false,
                    'created_at' => $trx->updated_at ?? now(),
                    'updated_at' => now()
                ]);
            }
        }

        $notifs = DB::table('notifications')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $notifs
        ]);

    }

    public function markAsRead(Request $request, $id)
    {
        DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json(['status' => 'success']);
    }

    public function markAllAsRead(Request $request)
    {
        DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json(['status' => 'success']);
    }
}

