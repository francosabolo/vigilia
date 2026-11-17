<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 PTZ cameras move on their own; M18 a sample of false-alarm closures is reviewed by the central.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->boolean('is_ptz')->default(false));
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('qa_requested_at')->nullable()->index();
            $table->string('qa_result', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $table) => $table->dropColumn(['qa_requested_at', 'qa_result']));
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn('is_ptz'));
    }
};
