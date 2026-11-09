<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el acceso en cuanto el trabajador vinculado pierde su área o se da de
 * baja, sin esperar a que caduque el token. Responde 401 para que el panel lo
 * trate como sesión terminada y regrese al login, donde se explica el motivo.
 *
 * También rechaza las sesiones de la app del trabajador (WorkerAccount):
 * comparten Sanctum con el panel, pero no tienen roles ni permisos.
 */
class EnsureSystemAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (! $user instanceof User || ! $user->hasSystemAccess())) {
            abort(401, 'Tu cuenta ya no tiene acceso al sistema.');
        }

        return $next($request);
    }
}
