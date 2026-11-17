<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Patrol\ClaudePatrolInspector;
use App\Services\Patrol\PassthroughPatrolInspector;
use App\Services\Patrol\PatrolInspector;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\PassthroughDetectionVerifier;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, fn (): Client => new Client(apiKey: config('services.anthropic.key')));

        $this->app->bind(DetectionVerifier::class, fn ($app): DetectionVerifier => config('seguria.ai.enabled')
            ? $app->make(ClaudeDetectionVerifier::class)
            : new PassthroughDetectionVerifier);

        $this->app->bind(PatrolInspector::class, fn ($app): PatrolInspector => config('seguria.ai.enabled')
            ? $app->make(ClaudePatrolInspector::class)
            : new PassthroughPatrolInspector);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Residents are invited through this email, so it has to read as an invitation, in Spanish.
        ResetPassword::toMailUsing(fn (object $notifiable, string $token): MailMessage => (new MailMessage)
            ->subject('Creá tu contraseña de Vigilia')
            ->greeting("Hola, {$notifiable->name}")
            ->line('Para entrar a Vigilia tenés que crear tu contraseña.')
            ->action('Crear contraseña', url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false)))
            ->line('El link vence en 60 minutos. Si no esperabas este mail, ignoralo.')
            ->salutation('Vigilia'));
    }
}
