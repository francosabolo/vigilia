<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual patrol: the AI goes over every camera of a site and reports only what is out of the ordinary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patrols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('running');
            $table->unsignedSmallInteger('cameras_total')->default(0);
            $table->unsignedSmallInteger('cameras_checked')->default(0);
            $table->json('findings')->nullable();
            $table->json('skipped')->nullable();
            $table->string('summary')->nullable();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'started_at']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('patrol_interval_minutes')->nullable()->after('ack_timeout_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('patrol_interval_minutes'));
        Schema::dropIfExists('patrols');
    }
};
