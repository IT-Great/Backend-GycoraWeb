<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Tambahkan kolom skor dan detail peringatan (JSON)
            $table->integer('fraud_score')->default(0)->after('status');
            $table->json('fraud_flags')->nullable()->after('fraud_score');
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['fraud_score', 'fraud_flags']);
        });
    }
};