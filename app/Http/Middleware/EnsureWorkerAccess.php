<?php

namespace App\Http\Middleware;

use App\Models\WorkerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas de la app del trabajador: solo sesiones de WorkerAccount, y solo
 * mientras el trabajador siga vigente. Responde 401, como EnsureSystemAccess,
 * para que la app lo trate como sesión terminada.
 */
class EnsureWorkerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof WorkerAccount || ! $account->hasAppAccess()) {
            abort(401, 'Tu acceso a la app ya no está disponible.');
        }

        return $next($request);
    }
}
