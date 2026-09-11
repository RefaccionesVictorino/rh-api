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
        // El frontend (Next.js) llama al login desde su servidor, por lo que todas
        // las peticiones comparten IP. Se limita por correo + IP para no bloquear a
        // toda la empresa cuando una sola cuenta acumula intentos fallidos.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(10)->by($email.'|'.$request->ip());
        });
    }
}
