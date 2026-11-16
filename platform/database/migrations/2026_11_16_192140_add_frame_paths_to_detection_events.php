<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A few frames of the clip, in time order: the AI judges the movement, not a single photo.
        Schema::table('detection_events', fn (Blueprint $table) => $table->json('frame_paths')->nullable()->after('clip_path'));
    }

    public function down(): void
    {
        Schema::table('detection_events', fn (Blueprint $table) => $table->dropColumn('frame_paths'));
    }
};
