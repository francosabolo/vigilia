<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K10 framing review, K13 equipment inventory, E6 remote edge update and H3 the security company's own brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cameras', 'edge_devices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('model', 80)->nullable();
                $table->string('serial', 80)->nullable();
                $table->date('installed_at')->nullable();
                $table->date('warranty_until')->nullable();
            });
        }
        Schema::table('cameras', function (Blueprint $table) {
            $table->json('framing_review')->nullable();
            $table->timestamp('framing_reviewed_at')->nullable();
        });
        Schema::table('edge_devices', fn (Blueprint $table) => $table->timestamp('update_requested_at')->nullable());
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('brand_name', 60)->nullable();
            $table->string('brand_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['brand_name', 'brand_color']));
        Schema::table('edge_devices', fn (Blueprint $table) => $table->dropColumn('update_requested_at'));
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn(['framing_review', 'framing_reviewed_at']));
        foreach (['cameras', 'edge_devices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['model', 'serial', 'installed_at', 'warranty_until']));
        }
    }
};
