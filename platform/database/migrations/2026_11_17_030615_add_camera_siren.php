<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K11 — the camera's own siren or alarm output, set up from the console (brand, address, user and password).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->text('siren')->nullable());
    }

    public function down(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn('siren'));
    }
};
