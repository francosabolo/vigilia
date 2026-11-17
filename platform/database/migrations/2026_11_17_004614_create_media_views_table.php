<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * J3 — who looked at which image (Ley 25.326: access to images of people must be traceable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->string('subject');
            $table->string('reference', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('viewed_at');

            $table->index(['site_id', 'viewed_at']);
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_views');
    }
};
