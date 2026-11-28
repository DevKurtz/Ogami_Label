<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UseLocalDesktopAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (filter_var(env('LOCAL_DESKTOP', false), FILTER_VALIDATE_BOOL)) {
            $user = User::query()->firstOrCreate(
                ['email' => 'desktop@ogami.local'],
                ['name' => 'Local workspace', 'password' => Str::random(64), 'email_verified_at' => now()],
            );
            Auth::setUser($user);
        }

        return $next($request);
    }
}
