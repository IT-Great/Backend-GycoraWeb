<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Menambahkan kolom untuk mencatat varian UI (A atau B)
            $table->string('ab_test_variant', 10)->nullable()->after('fraud_flags')
                  ->comment('Mencatat varian UI yang dilihat pembeli saat checkout (Contoh: A atau B)');
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('ab_test_variant');
        });
    }
};
