<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[\pL\pN][\pL\pN ._-]*$/u'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $username = Str::lower(Str::squish((string) $validated['username']));
        if (! Auth::attempt(['username' => $username, 'password' => $validated['password']], $request->boolean('remember'))) {
            return back()->withErrors(['credentials' => 'The username or password is incorrect.'])->onlyInput('username');
        }

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
