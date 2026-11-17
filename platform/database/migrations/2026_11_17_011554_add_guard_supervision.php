<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C2 — presence at the guard post and C3 — verified physical rounds. Both watch workers, so both start off and
 * require the company to confirm the guards were told in writing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->boolean('is_guard_post')->default(false);
            $table->boolean('is_checkpoint')->default(false);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('guard_post_max_absence_minutes')->nullable();
            $table->unsignedSmallInteger('round_interval_minutes')->nullable();
            $table->timestamp('guard_monitoring_consent_at')->nullable();
        });

        Schema::create('guard_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->boolean('present')->default(true);
            $table->string('description')->nullable();
            $table->timestamp('seen_at');

            $table->index(['camera_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guard_sightings');
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['guard_post_max_absence_minutes', 'round_interval_minutes', 'guard_monitoring_consent_at']));
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn(['is_guard_post', 'is_checkpoint']));
    }
};
