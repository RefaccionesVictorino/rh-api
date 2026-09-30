<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el acceso en cuanto el trabajador vinculado pierde su área o se da de
 * baja, sin esperar a que caduque el token. Responde 401 para que el panel lo
 * trate como sesión terminada y regrese al login, donde se explica el motivo.
 */
class EnsureSystemAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasSystemAccess()) {
            abort(401, 'Tu cuenta ya no tiene acceso al sistema.');
        }

        return $next($request);
    }
}
