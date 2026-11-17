<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('address')->nullable()->after('name');
            $table->decimal('lat', 10, 7)->nullable()->after('address');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['address', 'lat', 'lng']));
    }
};
