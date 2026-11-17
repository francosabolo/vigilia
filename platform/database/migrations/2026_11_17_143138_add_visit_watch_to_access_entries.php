<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D6 visitor off their route, D7 overdue visit, D8 leaving with things they did not bring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_entries', function (Blueprint $table) {
            $table->timestamp('expected_until')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->string('exit_photo_path')->nullable();
            $table->json('exit_check')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('access_entries', fn (Blueprint $table) => $table->dropColumn(['expected_until', 'overdue_notified_at', 'exit_photo_path', 'exit_check']));
    }
};
