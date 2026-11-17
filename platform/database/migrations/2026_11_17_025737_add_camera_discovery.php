<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K8 — find the cameras on the site's network (ONVIF) and add them to Frigate from the console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->timestamp('discovery_requested_at')->nullable();
            $table->json('discovered')->nullable();
            $table->timestamp('discovered_at')->nullable();
        });

        Schema::create('camera_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edge_device_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('host', 100);
            $table->text('stream_url')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('result')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_setups');
        Schema::table('edge_devices', fn (Blueprint $table) => $table->dropColumn(['discovery_requested_at', 'discovered', 'discovered_at']));
    }
};
