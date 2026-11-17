<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F7 — who to call when a resident is away or does not answer: family, a trusted neighbour, the house keeper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relation', 50)->nullable();
            $table->string('phone', 20);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_contacts');
    }
};
