<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\User;
use App\Services\OrgChartScope;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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

        // Por número de checador y no por IP, por la misma razón que el login.
        // Los intentos fallidos se limitan aparte, en IdentifyEmployeeRequest.
        RateLimiter::for('self-service', function (Request $request) {
            return Limit::perMinute(30)->by(strtoupper(preg_replace('/\s+/', '', (string) $request->input('pin'))));
        });

        // Complementa al permiso turnos.asignar: el permiso dice si el usuario
        // asigna turnos; esta regla, a quién.
        Gate::define('assign-shift', fn (User $user, Employee $employee): Response => app(OrgChartScope::class)->covers($user, $employee)
            ? Response::allow()
            : Response::deny("{$employee->full_name} no depende de ti en el organigrama."));

        // Toda ruta con {employee} (expediente, asistencia, turnos, vacaciones)
        // queda fuera del alcance de un jefe de área si el empleado no depende
        // de él. Se resuelve en el binding para no repetirlo en cada
        // controlador; Authenticate corre antes que SubstituteBindings, así que
        // aquí el usuario ya está identificado.
        Route::bind('employee', function (string $id): Employee {
            $employee = Employee::findOrFail($id);
            $user = request()->user();

            if ($user !== null && ! app(OrgChartScope::class)->covers($user, $employee)) {
                abort(403, "{$employee->full_name} no depende de ti en el organigrama.");
            }

            return $employee;
        });

        // Sin recorte de organigrama: reactivar es exclusivo de quien no lo tiene.
        Route::bind('terminated_employee', fn (string $id): Employee => Employee::onlyTrashed()->findOrFail($id));
    }
}
