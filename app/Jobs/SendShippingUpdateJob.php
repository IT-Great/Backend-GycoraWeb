<?php

namespace App\Jobs;

use App\Mail\ShippingUpdateMail;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendShippingUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transactionId;
    protected $status;

    public function __construct($transactionId, $status)
    {
        $this->transactionId = $transactionId;
        $this->status = strtolower($status);
    }

    public function handle(): void
    {
        $transaction = Transaction::with(['user', 'address'])->find($this->transactionId);

        if (!$transaction || !$transaction->user) {
            return;
        }

        $statusMapping = [
            'confirmed' => [
                'judul' => 'Pesanan Dikonfirmasi Kurir',
                'pesan' => 'Pihak ekspedisi telah menerima permintaan dan akan segera menjemput paket Anda.'
            ],
            'allocated' => [
                'judul' => 'Kurir Menuju Lokasi Penjemputan',
                'pesan' => 'Seorang kurir telah ditugaskan dan sedang menuju gudang kami untuk mengambil paket.'
            ],
            'picking_up' => [
                'judul' => 'Proses Penjemputan',
                'pesan' => 'Kurir saat ini sedang menjemput paket Anda.'
            ],
            'picked' => [
                'judul' => 'Paket Telah Dijemput',
                'pesan' => 'Paket Anda sudah diserahkan ke kurir dan memulai perjalanannya menuju alamat tujuan.'
            ],
            'dropping_off' => [
                'judul' => 'Paket Sedang Diantar',
                'pesan' => 'Kurir sedang dalam perjalanan menuju alamat Anda. Mohon pastikan ada penerima di lokasi.'
            ],
            'delivered' => [
                'judul' => 'Paket Telah Diterima',
                'pesan' => 'Paket Anda telah berhasil dikirimkan. Terima kasih telah berbelanja bersama kami!'
            ],
            'rejected' => [
                'judul' => 'Pengiriman Ditolak',
                'pesan' => 'Mohon maaf, pihak ekspedisi menolak pengiriman ini. Kami akan segera menindaklanjutinya.'
            ],
            'cancelled' => [
                'judul' => 'Pengiriman Dibatalkan',
                'pesan' => 'Proses pengiriman Anda telah dibatalkan oleh sistem.'
            ],
            'returned' => [
                'judul' => 'Paket Dikembalikan',
                'pesan' => 'Paket Anda dikembalikan ke gudang kami oleh pihak ekspedisi.'
            ],
            'disposed' => [
                'judul' => 'Pengiriman Gagal',
                'pesan' => 'Terdapat kendala serius pada pengiriman paket Anda. Silakan hubungi layanan pelanggan kami.'
            ],
        ];

        // Jika status yang dikirim ada di mapping, eksekusi email
        if (array_key_exists($this->status, $statusMapping)) {
            $statusJudul = $statusMapping[$this->status]['judul'];
            $statusPesan = $statusMapping[$this->status]['pesan'];

            try {
                Mail::to($transaction->user->email)->send(new ShippingUpdateMail($transaction, $statusPesan, $statusJudul));
            } catch (\Exception $e) {
                Log::error("Gagal kirim email shipping update ke {$transaction->user->email}: " . $e->getMessage());
            }
        }
    }
}
