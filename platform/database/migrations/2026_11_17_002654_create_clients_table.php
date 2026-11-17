<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client of the security company: the administration of a consorcio or a country, which can own several sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_id'));
        Schema::table('sites', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_id'));
        Schema::dropIfExists('clients');
    }
};
