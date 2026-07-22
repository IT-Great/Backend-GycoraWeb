<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_bundle_active')
                ->default(false)
                ->after('status');

            $table->decimal('bundle_price', 15, 2)
                ->nullable()
                ->after('voucher_discount_prices');

            $table->json('bundle_prices')
                ->nullable()
                ->after('bundle_price');

            $table->timestamp('bundle_end_date')
                ->nullable()
                ->after('bundle_prices');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_bundle_active',
                'bundle_price',
                'bundle_prices',
                'bundle_end_date',
            ]);
        });
    }
};
