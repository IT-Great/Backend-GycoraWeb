<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('point_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('set null');

            // Konteks transaksi (misal: 'checkout_usage', 'order_reward', 'refund', 'admin_adjustment')
            $table->string('reference_type', 50);
            $table->string('description', 255);

            // Double-entry columns
            $table->integer('debit')->default(0);  // Poin Keluar (Dikurangi)
            $table->integer('credit')->default(0); // Poin Masuk (Ditambah)
            $table->integer('balance');            // Saldo Akhir setelah baris ini dieksekusi

            // Cryptographic Audit Trail (Blockchain Logic)
            $table->string('previous_hash')->nullable();
            $table->string('signature'); // Hash HMAC SHA-256

            $table->timestamp('created_at')->useCurrent();
            // Tabel ini bersifat Append-Only (Tidak ada kolom updated_at karena data tidak boleh diubah)
        });
    }

    public function down()
    {
        Schema::dropIfExists('point_ledgers');
    }
};
