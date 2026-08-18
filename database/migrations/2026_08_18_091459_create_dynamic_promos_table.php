<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('dynamic_promos', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Contoh: "Merdeka Sale 2026"
            $table->string('banner_badge')->nullable(); // Contoh: "Merdeka Sale 🇮🇩"
            $table->dateTime('start_date');
            $table->dateTime('end_date');

            // Kolom sakti penyimpan aturan (JSON)
            $table->json('rules');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dynamic_promos');
    }
};
