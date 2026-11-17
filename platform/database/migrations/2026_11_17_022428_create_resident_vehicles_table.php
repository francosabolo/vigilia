<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F10 — the residents' cars: which owner has which car, so the gate recognizes them by the plate and anyone can be
 * found by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('plate', 15);
            $table->string('make_model', 60)->nullable();
            $table->string('color', 30)->nullable();
            $table->timestamps();

            $table->index(['site_id', 'plate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_vehicles');
    }
};
