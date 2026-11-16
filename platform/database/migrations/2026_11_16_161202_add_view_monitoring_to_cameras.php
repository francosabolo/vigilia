<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            // ok | moved | covered: result of comparing periodic frames with a reference view.
            $table->string('view_status')->default('ok')->after('range_m');
            $table->smallInteger('view_shift_deg')->nullable()->after('view_status');
            $table->unsignedTinyInteger('view_anomaly_count')->default(0)->after('view_shift_deg');
            $table->timestamp('view_checked_at')->nullable()->after('view_anomaly_count');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn(['view_status', 'view_shift_deg', 'view_anomaly_count', 'view_checked_at']));
    }
};
