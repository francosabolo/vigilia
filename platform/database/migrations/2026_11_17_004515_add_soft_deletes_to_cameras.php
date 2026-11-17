<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K6 — removing a camera hides it from the console and stops analyzing it, but keeps its incidents and
 * detections: they are evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
