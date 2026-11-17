<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K14 — a site that leaves the service is archived: it disappears from the console, its images are deleted and
 * its edge boxes stop being accepted. The incident records stay for the security company's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
