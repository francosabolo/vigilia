<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time codes (Google Authenticator, 1Password, Authy…) plus single-use recovery codes.
 */
class TwoFactor
{
    public function __construct(private readonly Google2FA $engine) {}

    public function newSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);

        return (new Writer(new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd)))->writeString($url);
    }

    /**
     * A code is valid once: replaying a code seen over someone's shoulder fails.
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || ! $this->engine->verifyKey($secret, $code, 1)) {
            return false;
        }

        return Cache::add("two-factor:used:{$user->id}:{$code}", true, now()->addMinutes(2));
    }

    /**
     * @return array<int, string>
     */
    public function newRecoveryCodes(): array
    {
        return Collection::times(8, fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $index = array_search(Str::lower(trim($code)), $codes, true);
        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }
}
