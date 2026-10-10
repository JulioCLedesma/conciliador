<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_sessions', function (Blueprint $table) {
            $table->string('left_catalog_name', 100)->default('Fifty');
            $table->string('right_catalog_name', 100)->default('PEMEX');
        });
        Schema::table('catalogs', function (Blueprint $table) {
            $table->string('classification_text')->default('Producto nuevo');
        });
        DB::table('catalogs')->where('side', 'fifty')->update(['classification_text' => 'Producto fuera de cuadro de PEMEX']);
    }

    public function down(): void
    {
        Schema::table('catalogs', function (Blueprint $table) {
            $table->dropColumn('classification_text');
        });
        Schema::table('reconciliation_sessions', function (Blueprint $table) {
            $table->dropColumn(['left_catalog_name', 'right_catalog_name']);
        });
    }
};
