<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K9 — installation test: for a few minutes the AI analyzes everything as usual but alerts nobody, so the
 * technician can walk in front of each camera and see what it caught.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('test_mode_until')->nullable();
        });

        Schema::table('detection_events', function (Blueprint $table) {
            $table->boolean('is_test')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('detection_events', fn (Blueprint $table) => $table->dropColumn('is_test'));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('test_mode_until'));
    }
};
