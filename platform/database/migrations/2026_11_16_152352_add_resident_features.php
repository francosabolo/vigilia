<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('lot')->nullable()->after('phone');
            $table->string('sector')->nullable()->after('lot');
            $table->timestamp('away_until')->nullable()->after('sector');
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->string('lot')->nullable()->after('is_perimeter');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('camera_id')->nullable()->change();
            $table->string('source')->default('detection')->after('zone_id');
            $table->string('sector')->nullable()->after('source');
            $table->foreignId('reported_by')->nullable()->after('sector')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_by');
            $table->dropColumn(['source', 'sector']);
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('lot');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['lot', 'sector', 'away_until']);
        });
    }
};
