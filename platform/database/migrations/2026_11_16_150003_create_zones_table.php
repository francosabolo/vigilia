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
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('frigate_zone');
            $table->boolean('is_perimeter')->default(true);
            $table->json('watched_labels')->nullable();
            $table->timestamps();

            $table->unique(['camera_id', 'frigate_zone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
