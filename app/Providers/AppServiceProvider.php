<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $perUser = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('label-read', fn (Request $request) => Limit::perMinute(240)->by($perUser($request)));
        RateLimiter::for('label-import', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('label-design', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('label-delete', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
    }
}
