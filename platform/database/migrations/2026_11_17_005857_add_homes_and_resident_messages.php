<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each resident's house on the map (countries and condominios), and the messages the staff sends to one or
 * several residents ("your garage door is open", "water cut tomorrow").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('home_lat', 10, 7)->nullable();
            $table->decimal('home_lng', 10, 7)->nullable();
        });

        Schema::create('resident_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->unsignedInteger('recipients');
            $table->boolean('urgent')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_messages');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['home_lat', 'home_lng']));
    }
};
