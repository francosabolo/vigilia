<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1–D3 — who may come in (a visit invited by a resident, a provider or domestic staff on set days) and who came in,
 * with the photo from the access camera and the plate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', fn (Blueprint $table) => $table->boolean('is_access')->default(false));

        Schema::create('access_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resident_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('name');
            $table->string('document', 30)->nullable();
            $table->string('plate', 15)->nullable();
            $table->string('code', 6);
            $table->uuid('token')->unique();
            $table->date('valid_from');
            $table->date('valid_until');
            $table->json('days')->nullable();
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'code']);
        });

        Schema::create('access_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('authorization_id')->nullable()->constrained('access_authorizations')->nullOnDelete();
            $table->foreignId('resident_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guard_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('name');
            $table->string('plate', 15)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20);
            $table->string('reason')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_entries');
        Schema::dropIfExists('access_authorizations');
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn('is_access'));
    }
};
