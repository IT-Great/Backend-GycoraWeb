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
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->foreign(['clinic_treatment_id'])->references(['id'])->on('clinic_treatments')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->dropForeign('clinic_appointments_clinic_treatment_id_foreign');
        });
    }
};
