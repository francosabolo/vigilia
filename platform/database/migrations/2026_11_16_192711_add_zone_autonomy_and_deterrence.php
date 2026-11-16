<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->string('autonomy')->default('assisted')->after('kind');
            $table->timestamp('autonomy_changed_at')->nullable()->after('autonomy');
        });

        // Speaker / siren / lights triggered on the edge before the guard arrives.
        Schema::create('deterrence_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edge_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->string('message');
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deterrence_commands');
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn(['autonomy', 'autonomy_changed_at']));
    }
};
