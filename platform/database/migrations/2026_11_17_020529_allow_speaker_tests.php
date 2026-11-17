<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K11 — a speaker test is a deterrence command without an incident; B7 — the operator's own words are one with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deterrence_commands', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->change();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deterrence_commands', fn (Blueprint $table) => $table->dropConstrainedForeignId('requested_by'));
    }
};
