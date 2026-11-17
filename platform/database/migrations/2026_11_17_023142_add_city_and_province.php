<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address split as people write it in Argentina (street and number, city, province), so maps start in the
 * right place and reports read well.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sites', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('city', 100)->nullable()->after('address');
                $table->string('province', 60)->nullable()->after('city');
            });
        }
    }

    public function down(): void
    {
        foreach (['sites', 'clients'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['city', 'province']));
        }
    }
};
