<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * First account of a new installation: the security company and its central administrator. Prints a link to choose
 * the password, so it works before e-mail is configured.
 */
class CreateAdmin extends Command
{
    protected $signature = 'vigilia:create-admin {email} {name} {--company= : Name of the security company}';

    protected $description = 'Create the security company and its first central administrator';

    public function handle(): int
    {
        $company = $this->option('company') ?: 'Mi empresa de seguridad';
        $organization = Organization::firstOrCreate(['slug' => Str::slug($company)], ['name' => $company]);

        $user = User::firstOrCreate(['email' => $this->argument('email')], [
            'name' => $this->argument('name'),
            'password' => Str::random(40),
            'role' => UserRole::Admin,
            'organization_id' => $organization->id,
            'email_verified_at' => now(),
        ]);

        $token = Password::createToken($user);
        $this->info("Administrador listo: {$user->email} ({$organization->name}).");
        $this->line('Elegí la contraseña en: '.url(route('password.reset', ['token' => $token, 'email' => $user->email], false)));

        return self::SUCCESS;
    }
}
