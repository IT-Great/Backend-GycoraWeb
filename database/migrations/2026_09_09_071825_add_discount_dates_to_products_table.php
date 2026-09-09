<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('discount_start_date')
                ->nullable()
                ->after('discount_price');

            $table->timestamp('discount_end_date')
                ->nullable()
                ->after('discount_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'discount_start_date',
                'discount_end_date',
            ]);
        });
    }
};
