<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('detection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edge_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('label');
            $table->float('score')->nullable();
            $table->json('zones')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('snapshot_path')->nullable();
            $table->string('clip_path')->nullable();
            $table->string('status')->default('pending');
            $table->json('ai_verdict')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['edge_device_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detection_events');
    }
};
