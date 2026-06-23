<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Memaksa MySQL untuk memperbarui struktur ENUM dengan menambahkan 'reseller'
        DB::statement("ALTER TABLE users MODIFY COLUMN usertype ENUM('user', 'admin', 'superadmin', 'gudang', 'accounting', 'reseller') NOT NULL DEFAULT 'user'");
    }

    public function down(): void
    {
        // Rollback ke struktur aslinya
        DB::statement("ALTER TABLE users MODIFY COLUMN usertype ENUM('user', 'admin', 'superadmin', 'gudang', 'accounting') NOT NULL DEFAULT 'user'");
    }
};