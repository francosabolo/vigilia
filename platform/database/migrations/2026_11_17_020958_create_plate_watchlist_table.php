<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A7 — plates the guard must be warned about at the access (a car linked to a previous incident, a banned visitor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plate_watchlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('plate', 15);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'plate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plate_watchlist');
    }
};
