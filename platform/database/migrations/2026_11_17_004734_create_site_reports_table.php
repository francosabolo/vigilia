<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1 monthly service report per site and B9 incident report: the AI-written text is stored so it is paid once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->text('narrative');
            $table->timestamp('generated_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'month']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->text('report_narrative')->nullable();
            $table->timestamp('report_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $table) => $table->dropColumn(['report_narrative', 'report_generated_at']));
        Schema::dropIfExists('site_reports');
    }
};
