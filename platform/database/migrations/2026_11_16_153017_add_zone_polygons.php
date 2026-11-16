<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            // Polygon drawn in the console, normalized to 0..1 so it is independent of the stream resolution.
            $table->json('coordinates')->nullable()->after('frigate_zone');
        });

        Schema::table('edge_devices', function (Blueprint $table) {
            $table->string('zones_version', 40)->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->dropColumn('zones_version');
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('coordinates');
        });
    }
};
