<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GoogleAuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        return view('auth.login', [
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $clientId = (string) config('services.google.client_id', '');
        $clientSecret = (string) config('services.google.client_secret', '');
        $callback = (string) config('services.google.redirect', '');

        if ($clientId === '' || $clientSecret === '' || $callback === '') {
            return redirect()->route('login')->withErrors(['google' => 'Google sign-in is not configured yet. Add the Google OAuth settings in Vercel first.']);
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('google_oauth_state', '');
        $providedState = (string) $request->query('state', '');

        if ($expectedState === '' || $providedState === '' || ! hash_equals($expectedState, $providedState)) {
            return redirect()->route('login')->withErrors(['google' => 'The Google sign-in request expired. Please try again.']);
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('login')->withErrors(['google' => 'Google sign-in was cancelled or could not be completed.']);
        }

        try {
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $request->query('code'),
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect'),
                'grant_type' => 'authorization_code',
            ])->throw()->json();

            $profile = Http::withToken($tokenResponse['access_token'] ?? '')
                ->get('https://openidconnect.googleapis.com/v1/userinfo')
                ->throw()
                ->json();
        } catch (\Throwable) {
            return redirect()->route('login')->withErrors(['google' => 'Google could not verify your account. Please try again.']);
        }

        $googleId = (string) ($profile['sub'] ?? '');
        $email = Str::lower(trim((string) ($profile['email'] ?? '')));

        if ($googleId === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return redirect()->route('login')->withErrors(['google' => 'Use a Google account with a verified email address.']);
        }

        $user = User::query()->where('google_id', $googleId)->first();
        if (! $user) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user && filled($user->google_id) && ! hash_equals((string) $user->google_id, $googleId)) {
                return redirect()->route('login')->withErrors(['google' => 'This email is already connected to another Google account.']);
            }

            $user ??= new User;
            $user->name = trim((string) ($profile['name'] ?? '')) ?: $email;
            $user->email = $email;
            $user->email_verified_at ??= now();
            $user->google_id = $googleId;
            $user->password ??= Str::random(64);
            $user->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
