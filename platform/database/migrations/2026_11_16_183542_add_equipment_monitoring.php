<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->timestamp('offline_since')->nullable()->after('last_seen_at');
        });

        Schema::table('cameras', function (Blueprint $table) {
            $table->timestamp('offline_since')->nullable()->after('last_seen_at');
        });

        Schema::table('incidents', function (Blueprint $table) {
            // Which edge box an "equipment" incident is about (cameras use camera_id).
            $table->foreignId('edge_device_id')->nullable()->after('camera_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $table) => $table->dropConstrainedForeignId('edge_device_id'));
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn('offline_since'));
        Schema::table('edge_devices', fn (Blueprint $table) => $table->dropColumn('offline_since'));
    }
};
