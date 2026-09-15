<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use App\Services\TimeClock\ZkCommandBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un trabajador con su usuario de checador.
 *
 * Un empleado sin usuario de checador no puede registrar asistencia, así que
 * ambos se crean en la misma transacción: si algo falla, no queda un expediente
 * a medias que alguien tenga que completar a mano después.
 *
 * Los comandos hacia las terminales se encolan; el equipo los aplica en su
 * siguiente sondeo. Por eso el alta puede terminar bien aunque ningún checador
 * esté en línea en ese momento.
 */
class EmployeeRegistrationService
{
    public function __construct(private readonly ZkCommandBuilder $commands) {}

    /**
     * @param  array<string, mixed>  $attributes  Datos ya validados del expediente.
     * @return array{employee: Employee, devices: int}  `devices` = terminales a las que se encoló el alta.
     */
    public function register(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $employee = Employee::create($attributes);

            $user = TimeClockUser::create([
                // El RFC es el PIN con el que checa. Cabe en la columna (20) y
                // ya se validó único contra time_clock_users.
                'pin' => $employee->rfc,
                // El equipo corta el nombre a 60 caracteres; se recorta aquí
                // para que lo guardado coincida con lo que muestra la terminal.
                'name' => mb_substr($employee->full_name, 0, 60),
                'privilege' => TimeClockUser::PRIVILEGE_USER,
                'employee_id' => $employee->id,
            ]);

            $devices = TimeClockDevice::where('is_active', true)->get();

            $devices->each(fn (TimeClockDevice $device) => $this->commands->upsertUser($device, $user));

            return ['employee' => $employee, 'devices' => $devices->count()];
        });
    }

    /**
     * Mensaje para RH sobre el estado de la sincronización.
     *
     * El rostro nunca viaja en el alta: se enrola frente a cada terminal. Sin
     * equipos activos el usuario queda solo en el sistema, y se sincronizará
     * cuando se dé de alta una terminal.
     */
    public function syncMessage(int $devices): string
    {
        return $devices === 0
            ? 'Trabajador creado. No hay terminales activas, así que su alta en el checador quedó pendiente de enviar.'
            : "Trabajador creado y enviado a {$devices} terminal(es). Falta enrolar el rostro en el equipo.";
    }
}
