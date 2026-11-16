<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('guard')->after('email');
            $table->foreignId('organization_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->string('phone')->nullable()->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['role', 'phone']);
        });
    }
};
