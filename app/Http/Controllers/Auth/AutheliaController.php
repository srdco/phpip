<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AutheliaAccounts;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Authelia\Provider;

class AutheliaController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $provider = $this->provider();
        $request->session()->put('authelia_started_at', time());

        return $provider->redirect();
    }

    public function callback(Request $request, AutheliaAccounts $accounts): RedirectResponse
    {
        $provider = $this->provider();
        $startedAt = $request->session()->pull('authelia_started_at');

        if (! is_int($startedAt) || $startedAt < time() - 600 || $startedAt > time()
            || $request->has('error') || ! is_string($request->query('code'))
            || $request->query('code') === '' || ! is_string($request->query('state'))
            || $request->query('state') === '') {
            $request->session()->forget(['state', 'code_verifier']);

            return $this->failed();
        }

        try {
            // Keep Socialite's session/state verification enabled. PKCE binds the code
            // exchange to the browser that started the sign-in.
            $identity = $provider->user();
        } catch (InvalidStateException|GuzzleException|\TypeError|\ErrorException $exception) {
            // Provider exceptions can contain tokens or client credentials. Do not log
            // them or expose the upstream response to the browser.
            return $this->failed();
        } finally {
            $request->session()->forget(['state', 'code_verifier']);
        }

        $subject = $identity->getId();
        if (! is_string($subject) || ! preg_match('/\A[\x21-\x7E]{1,255}\z/', $subject)) {
            return $this->failed();
        }

        $user = $accounts->resolve(
            rtrim(config('services.authelia.base_url'), '/'),
            $subject,
            is_string($identity->getName()) ? $identity->getName() : null,
        );

        if (! $user) {
            return $this->failed();
        }

        // Roles and company scope come exclusively from the existing phpIP account.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/home');
    }

    private function provider(): Provider
    {
        abort_unless(config('services.authelia.enabled'), 404);

        $config = config('services.authelia');
        $issuer = $config['base_url'] ?? '';
        abort_unless(is_string($issuer) && strlen($issuer) <= 255
            && filter_var($issuer, FILTER_VALIDATE_URL)
            && parse_url($issuer, PHP_URL_SCHEME) === 'https'
            && ! parse_url($issuer, PHP_URL_USER) && ! parse_url($issuer, PHP_URL_PASS)
            && ! parse_url($issuer, PHP_URL_QUERY) && ! parse_url($issuer, PHP_URL_FRAGMENT)
            && ! empty($config['client_id']) && ! empty($config['client_secret'])
            && ! empty($config['redirect']), 503, 'Authelia sign-in is not configured.');

        return Socialite::driver('authelia')->setScopes(['openid', 'profile'])->enablePKCE();
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'authelia' => __('Unable to sign in with Authelia. Try again or contact your administrator.'),
        ]);
    }
}
