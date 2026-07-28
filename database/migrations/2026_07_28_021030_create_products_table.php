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
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->index('products_category_id_foreign');
            $table->string('sku', 100)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('benefits')->nullable();
            $table->decimal('price', 12);
            $table->json('prices')->nullable();
            $table->decimal('wholesale_price', 15)->nullable();
            $table->json('wholesale_prices')->nullable();
            $table->decimal('discount_price', 15)->nullable();
            $table->json('discount_prices')->nullable();
            $table->decimal('voucher_discount_price', 15)->nullable();
            $table->json('voucher_discount_prices')->nullable();
            $table->integer('stock')->default(0);
            $table->string('image_url')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('is_bundle_active')->default(false);
            $table->decimal('bundle_price', 15)->nullable();
            $table->json('bundle_prices')->nullable();
            $table->timestamp('bundle_start_date')->nullable();
            $table->timestamp('bundle_end_date')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
            $table->longText('variant_images')->nullable();
            $table->string('variant_video', 500)->nullable();
            $table->longText('color')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
