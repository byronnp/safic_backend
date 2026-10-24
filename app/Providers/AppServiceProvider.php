<?php

namespace App\Providers;

use App\Core\Access\Catalogo;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El catálogo de permisos y roles se lee de la base una vez por petición o trabajo
        $this->app->scoped(Catalogo::class);
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
