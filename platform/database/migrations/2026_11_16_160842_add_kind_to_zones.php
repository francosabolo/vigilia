<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            // perimeter | door | window | sensitive
            $table->string('kind')->default('perimeter')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
