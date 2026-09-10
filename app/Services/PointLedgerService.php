<?php

namespace App\Services;

use Exception;
use App\Models\User;
use App\Models\PointLedger;
use Illuminate\Support\Facades\DB;

class PointLedgerService
{
    /**
     * Menambah poin pengguna (Credit)
     */
    public static function addPoints(int $userId, int $amount, string $refType, string $description, ?int $transactionId = null)
    {
        if ($amount <= 0) throw new Exception("Jumlah poin penambahan harus lebih besar dari 0.");
        return self::processTransaction($userId, 0, $amount, $refType, $description, $transactionId);
    }

    /**
     * Mengurangi poin pengguna (Debit)
     */
    public static function deductPoints(int $userId, int $amount, string $refType, string $description, ?int $transactionId = null)
    {
        if ($amount <= 0) throw new Exception("Jumlah poin pemotongan harus lebih besar dari 0.");
        return self::processTransaction($userId, $amount, 0, $refType, $description, $transactionId);
    }

    /**
     * Engine Utama yang menjalankan Double-Entry Bookkeeping & SHA-256 Hashing
     */
    private static function processTransaction(int $userId, int $debit, int $credit, string $refType, string $desc, ?int $transactionId)
    {
        // 🛡️ PESSIMISTIC LOCKING: DB::transaction memastikan Race-Condition tidak akan terjadi.
        // Jika ada 2 request masuk milidetik yang sama, request ke-2 akan dipaksa mengantre.
        return DB::transaction(function () use ($userId, $debit, $credit, $refType, $desc, $transactionId) {

            // 1. Kunci baris user ini di database sampai proses selesai (lockForUpdate)
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (!$user) throw new Exception("Pengguna tidak ditemukan.");

            // 2. Kalkulasi saldo baru
            $currentBalance = $user->point;
            $newBalance = $currentBalance - $debit + $credit;

            if ($newBalance < 0) {
                throw new Exception("Saldo poin tidak mencukupi untuk transaksi ini.");
            }

            // 3. Ambil rantai hash terakhir (Block sebelumnya)
            $lastLedger = PointLedger::where('user_id', $userId)->orderBy('id', 'desc')->first();
            $previousHash = $lastLedger ? $lastLedger->signature : config('app.key'); // Genesis block hash

            // 4. Buat Tanda Tangan Kriptografi (Cryptographic Signature) untuk baris ini
            // Menggabungkan data penting + hash lama + APP_KEY (Salt rahasia)
            $payload = "{$userId}|{$transactionId}|{$debit}|{$credit}|{$newBalance}|{$previousHash}";
            $signature = hash_hmac('sha256', $payload, config('app.key'));

            // 5. Catat ke Buku Besar (Ledger)
            PointLedger::create([
                'user_id'        => $userId,
                'transaction_id' => $transactionId,
                'reference_type' => $refType,
                'description'    => $desc,
                'debit'          => $debit,
                'credit'         => $credit,
                'balance'        => $newBalance,
                'previous_hash'  => $previousHash,
                'signature'      => $signature,
                'created_at'     => now()
            ]);

            // 6. Sinkronisasi saldo akhir ke tabel users
            $user->point = $newBalance;
            $user->save();

            return $newBalance;
        });
    }

    /**
     * Fitur untuk Tim Audit: Mengecek apakah ada data yang dimanipulasi manual di Database
     */
    public static function verifyLedgerIntegrity(int $userId): bool
    {
        $ledgers = PointLedger::where('user_id', $userId)->orderBy('id', 'asc')->get();
        $expectedPrevHash = config('app.key'); // Genesis

        foreach ($ledgers as $ledger) {
            // Cek rantai hash
            if ($ledger->previous_hash !== $expectedPrevHash) return false;

            // Cek validitas signature
            $payload = "{$ledger->user_id}|{$ledger->transaction_id}|{$ledger->debit}|{$ledger->credit}|{$ledger->balance}|{$ledger->previous_hash}";
            $expectedSignature = hash_hmac('sha256', $payload, config('app.key'));

            if ($ledger->signature !== $expectedSignature) return false; // Terjadi manipulasi (Tampering)!

            $expectedPrevHash = $ledger->signature;
        }

        return true;
    }
}
