<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edge_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('snapshot_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['edge_device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_requests');
    }
};
