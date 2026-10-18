<?php

namespace App\Providers;

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
        // Canales privados de tiempo real (Reverb); la ruta de autorización está en routes/api.php
        require base_path('routes/channels.php');
    }
}
