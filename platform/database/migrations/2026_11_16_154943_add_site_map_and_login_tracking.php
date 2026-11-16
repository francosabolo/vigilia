<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Perimeter polygon on the map: [[lat, lng], ...].
            $table->json('perimeter')->nullable()->after('address');
            $table->decimal('lat', 10, 7)->nullable()->after('perimeter');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });

        Schema::table('cameras', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('sector');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            // Where the camera points (degrees from north, clockwise), its field of view and useful range.
            $table->unsignedSmallInteger('heading')->default(0)->after('lng');
            $table->unsignedSmallInteger('fov')->default(90)->after('heading');
            $table->unsignedSmallInteger('range_m')->default(40)->after('fov');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('away_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_login_at'));
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn(['lat', 'lng', 'heading', 'fov', 'range_m']));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['perimeter', 'lat', 'lng']));
    }
};
