<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4 power cut (edge on UPS battery), M5 internet cut (events kept and sent later), M7 motion without a person,
 * M13 smoke or fire watched live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->timestamp('on_battery_since')->nullable();
            $table->unsignedInteger('queued_events')->nullable();
            $table->json('connectivity_gaps')->nullable();
        });
        Schema::table('cameras', function (Blueprint $table) {
            $table->boolean('watch_motion')->default(false);
            $table->boolean('fire_watch')->default(false);
            $table->timestamp('fire_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn(['watch_motion', 'fire_watch', 'fire_checked_at']));
        Schema::table('edge_devices', fn (Blueprint $table) => $table->dropColumn(['on_battery_since', 'queued_events', 'connectivity_gaps']));
    }
};
