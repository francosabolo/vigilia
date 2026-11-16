<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What really happened, when someone knows: closed incidents, the simulator, or a person reviewing.
        Schema::table('detection_events', function (Blueprint $table) {
            $table->boolean('ground_truth')->nullable()->after('ai_verdict');
            $table->string('ground_truth_source')->nullable()->after('ground_truth');
            $table->foreignId('reviewed_by')->nullable()->after('ground_truth_source')->constrained('users')->nullOnDelete();
        });

        Schema::create('shadow_verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detection_event_id')->constrained()->cascadeOnDelete();
            $table->string('challenger')->index();
            $table->string('model');
            $table->string('prompt', 12);
            $table->boolean('champion_alerted');
            $table->boolean('alerted');
            $table->boolean('is_threat');
            $table->float('confidence')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['detection_event_id', 'challenger']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_verdicts');
        Schema::table('detection_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['ground_truth', 'ground_truth_source']);
        });
    }
};
