<?php

namespace App\Core;

use App\Core\Console\GenerarLlavesJwt;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantDatabase;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: una instancia por petición o job (seguro también con Octane)
        $this->app->scoped(TenantDatabase::class);
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerarLlavesJwt::class]);
        }

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('refresh', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
