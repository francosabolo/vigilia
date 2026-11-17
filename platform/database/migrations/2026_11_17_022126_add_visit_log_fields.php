<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5 — the visit log: who came, to which unit, to see whom, why, and when they left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_entries', function (Blueprint $table) {
            $table->string('unit', 20)->nullable()->after('resident_id');
            $table->string('visiting')->nullable()->after('unit');
            $table->string('document', 30)->nullable()->after('name');
            $table->string('purpose')->nullable()->after('plate');
            $table->timestamp('exited_at')->nullable();
            $table->index(['site_id', 'unit']);
        });
    }

    public function down(): void
    {
        Schema::table('access_entries', fn (Blueprint $table) => $table->dropColumn(['unit', 'visiting', 'document', 'purpose', 'exited_at']));
    }
};
