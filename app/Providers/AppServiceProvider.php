<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
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
        // The Render TLS proxy may report an HTTP origin to PHP. Use same-origin
        // paths so browsers always load the built assets over the page's HTTPS URL.
        if (app()->environment('production')) {
            Vite::createAssetPathsUsing(fn (string $path) => '/'.ltrim($path, '/'));
        }

        RateLimiter::for('demo-read', fn (Request $request) => app()->environment('production')
            ? Limit::perMinute(120)->by('read:'.$request->ip())
            : Limit::none());

        RateLimiter::for('demo-write', fn (Request $request) => app()->environment('production')
            ? Limit::perMinute(20)->by('write:'.$request->ip())
            : Limit::none());
    }
}
