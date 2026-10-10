<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_links', function (Blueprint $table) {
            $table->unique(['fifty_id', 'pemex_id'], 'catalog_link_pair');
        });
        Schema::table('catalog_links', function (Blueprint $table) {
            $table->dropUnique('catalog_links_fifty_id_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('catalog_links')->select('fifty_id')->groupBy('fifty_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('No se puede restaurar la regla anterior sin perder equivalencias.');
        }
        Schema::table('catalog_links', function (Blueprint $table) {
            $table->unique('fifty_id');
            $table->dropUnique('catalog_link_pair');
        });
    }
};
