<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detection_events', function (Blueprint $table) {
            // Step-by-step record of how the system decided: detector → rules → AI → decision.
            $table->json('trace')->nullable()->after('ai_verdict');
            $table->index(['camera_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('detection_events', function (Blueprint $table) {
            $table->dropIndex(['camera_id', 'created_at']);
            $table->dropColumn('trace');
        });
    }
};
