<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Data spesifik B2B / Business Partner
            $table->string('business_name'); // Nama Toko / Bisnis
            $table->string('sales_platform'); // Contoh: Shopee, Instagram, Toko Fisik
            $table->string('monthly_capacity'); // Contoh: "10-50 pcs", ">100 pcs"
            $table->text('additional_notes')->nullable(); // Pesan tambahan dari calon mitra

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_applications');
    }
};
