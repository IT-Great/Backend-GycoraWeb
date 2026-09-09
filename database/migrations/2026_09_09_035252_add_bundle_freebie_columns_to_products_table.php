<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('has_bundle_freebie')
                ->default(false)
                ->after('bundle_end_date');

            $table->string('bundle_freebie_name')
                ->nullable()
                ->after('has_bundle_freebie');

            $table->integer('bundle_freebie_quota')
                ->default(0)
                ->after('bundle_freebie_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'has_bundle_freebie',
                'bundle_freebie_name',
                'bundle_freebie_quota',
            ]);
        });
    }
};
