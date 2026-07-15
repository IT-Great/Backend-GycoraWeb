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
        Schema::create('reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index('reviews_user_id_foreign');
            $table->unsignedBigInteger('product_id')->nullable()->index('reviews_product_id_foreign');
            $table->unsignedBigInteger('clinic_treatment_id')->nullable()->index('reviews_clinic_treatment_id_foreign');
            $table->string('transaction_id')->nullable();
            $table->tinyInteger('rating')->comment('1 to 5');
            $table->text('comment')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
