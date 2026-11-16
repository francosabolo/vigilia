<?php

use App\Enums\UserRole;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

function userWithTwoFactor(array $attributes = []): User
{
    return User::factory()->create($attributes + [
        'two_factor_secret' => app(Google2FA::class)->generateSecretKey(32),
        'two_factor_recovery_codes' => ['aaaaa-bbbbb', 'ccccc-ddddd'],
        'two_factor_confirmed_at' => now(),
    ]);
}

function currentCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
}

it('asks for the code after the password and logs in with it', function () {
    $user = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => currentCode($user)])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

it('accepts each recovery code only once', function () {
    $user = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => 'aaaaa-bbbbb'])->assertRedirect('/dashboard');
    expect($user->fresh()->two_factor_recovery_codes)->toBe(['ccccc-ddddd']);

    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => 'aaaaa-bbbbb'])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('does not open the challenge without a password step first', function () {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
});

it('sets up two-factor by confirming a code from the app', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($user)->post('/settings/two-factor')->assertRedirect();
    $secret = $user->fresh()->two_factor_secret;
    expect($secret)->not->toBeNull()->and($user->fresh()->hasTwoFactor())->toBeFalse();

    $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect('/settings/two-factor')
        ->assertSessionHas('two_factor_recovery_codes');

    expect($user->fresh()->hasTwoFactor())->toBeTrue();
});

it('makes the central turn on two-factor before using the console when required', function () {
    config(['seguria.require_two_factor' => true]);
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $guard = User::factory()->create(['role' => UserRole::Guard]);

    $this->actingAs($operator)->get('/dashboard')->assertRedirect('/settings/two-factor');
    $this->actingAs(userWithTwoFactor(['role' => UserRole::Operator]))->get('/dashboard')->assertOk();
    $this->actingAs($guard)->get('/dashboard')->assertOk();

    $this->actingAs($withTwoFactor = userWithTwoFactor(['role' => UserRole::Admin]))
        ->delete('/settings/two-factor', ['password' => 'password'])
        ->assertSessionHasErrors('password');
    expect($withTwoFactor->fresh()->hasTwoFactor())->toBeTrue();
});
