<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('reconciliation_sessions');
            $table->string('side', 10);
            $table->string('filename');
            $table->string('path');
            $table->string('sheet');
            $table->unsignedInteger('header_row');
            $table->json('headers');
            $table->json('preamble');
            $table->unsignedInteger('reference_column');
            $table->json('description_columns');
            $table->json('sensitive_columns');
            $table->unsignedInteger('result_column');
            $table->string('result_name');
            $table->timestamps();
            $table->unique(['session_id', 'side']);
            $table->unique(['id', 'session_id', 'side'], 'catalog_identity');
        });
        Schema::create('catalog_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_id');
            $table->unsignedBigInteger('session_id');
            $table->string('side', 10);
            $table->foreign(['catalog_id', 'session_id', 'side'], 'row_catalog_identity')->references(['id', 'session_id', 'side'])->on('catalogs');
            $table->unsignedInteger('original_row');
            $table->text('reference');
            $table->json('values');
            $table->text('description');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(0);
            $table->unique(['catalog_id', 'original_row']);
            $table->unique(['id', 'session_id', 'side'], 'row_identity');
            $table->index(['catalog_id', 'status']);
        });
        Schema::create('catalog_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('fifty_id')->unique();
            $table->unsignedBigInteger('pemex_id');
            $table->string('fifty_side', 10)->default('fifty');
            $table->string('pemex_side', 10)->default('pemex');
            $table->foreign(['fifty_id', 'session_id', 'fifty_side'], 'link_fifty_identity')->references(['id', 'session_id', 'side'])->on('catalog_rows');
            $table->foreign(['pemex_id', 'session_id', 'pemex_side'], 'link_pemex_identity')->references(['id', 'session_id', 'side'])->on('catalog_rows');
            $table->timestamps();
        });
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE catalog_links ADD CONSTRAINT link_correct_sides CHECK (fifty_side = 'fifty' AND pemex_side = 'pemex')");
        } else {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement("CREATE TRIGGER link_correct_sides_{$event} BEFORE {$event} ON catalog_links WHEN NEW.fifty_side != 'fifty' OR NEW.pemex_side != 'pemex' BEGIN SELECT RAISE(ABORT, 'Invalid catalog sides'); END");
            }
        }
        Schema::create('reconciliation_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('reconciliation_sessions');
            $table->foreignId('user_id')->constrained('users');
            $table->string('action');
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_audits');
        Schema::dropIfExists('catalog_links');
        Schema::dropIfExists('catalog_rows');
        Schema::dropIfExists('catalogs');
        Schema::dropIfExists('reconciliation_sessions');
    }
};
