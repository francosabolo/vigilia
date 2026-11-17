<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I6 — what the AI should know about each camera ("the dog of lot 12 walks by every night"), learned from the
 * false alarms the guards close and written by the managers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->text('ai_notes')->nullable());
    }

    public function down(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn('ai_notes'));
    }
};
