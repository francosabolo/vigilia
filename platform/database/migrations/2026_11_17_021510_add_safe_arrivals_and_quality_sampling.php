<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F9 — safe arrival: the resident says they are arriving and the guard watches until they are in.
 * I7 — a weekly random sample of what the AI dismissed, reviewed by a person, to measure what it misses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_arrivals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('arriving');
            $table->timestamp('expected_by');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });

        Schema::table('detection_events', fn (Blueprint $table) => $table->timestamp('qa_requested_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('detection_events', fn (Blueprint $table) => $table->dropColumn('qa_requested_at'));
        Schema::dropIfExists('safe_arrivals');
    }
};
