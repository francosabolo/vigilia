<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4–H6 — what the security company charges, what it promises and what it costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('currency', 3)->default('ARS');
            $table->decimal('price_per_camera', 12, 2)->nullable();
            $table->decimal('operator_hour_cost', 12, 2)->nullable();
            $table->decimal('usd_rate', 12, 2)->nullable();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('price_per_camera', 12, 2)->nullable();
            $table->unsignedInteger('sla_ack_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['price_per_camera', 'sla_ack_seconds']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['currency', 'price_per_camera', 'operator_hour_cost', 'usd_rate']));
    }
};
