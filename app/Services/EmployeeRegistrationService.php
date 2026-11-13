<?php

namespace App\Services;

use App\Jobs\RecordAttendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use App\Services\TimeClock\ZkCommandBuilder;
use Carbon\CarbonImmutable;
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
     * @param  array<int, array<string, mixed>>  $emergencyContacts
     * @return array{employee: Employee, devices: int, adopted: bool, punches: int}
     *         `devices` = terminales a las que se encoló el alta; `adopted` = el
     *         usuario ya existía en el checador y solo se ligó; `punches` =
     *         checadas previas de ese PIN que pasaron al expediente.
     */
    public function register(array $attributes, array $emergencyContacts): array
    {
        $result = DB::transaction(function () use ($attributes, $emergencyContacts): array {
            $employee = Employee::create($attributes);
            $employee->syncEmergencyContacts($emergencyContacts);

            TimeClockUser::releaseTrashed($employee->rfc);

            /*
             * El RFC es el PIN con el que checa. Si el trabajador ya fue
             * enrolado en la terminal antes de capturarlo en RH, el equipo
             * reportó un usuario con ese PIN sin empleado: se adopta en lugar
             * de crear otro, para no perder el rostro ya enrolado ni duplicar
             * el PIN en las terminales.
             */
            $user = TimeClockUser::firstOrNew(['pin' => $employee->rfc]);
            $adopted = $user->exists;

            $user->fill([
                // El equipo corta el nombre a 60 caracteres; se recorta aquí
                // para que lo guardado coincida con lo que muestra la terminal.
                'name' => mb_substr($employee->full_name, 0, 60),
                'employee_id' => $employee->id,
                'is_active' => true,
            ]);
            $user->save();

            $punches = $adopted ? $this->adoptPunches($employee) : null;

            $devices = TimeClockDevice::where('is_active', true)->get();

            $devices->each(fn (TimeClockDevice $device) => $this->commands->upsertUser($device, $user));

            return [
                'employee' => $employee,
                'devices' => $devices->count(),
                'adopted' => $adopted,
                'punches' => $punches,
            ];
        });

        $punches = $result['punches'];

        // Fuera de la transacción: el job debe ver las checadas ya religadas.
        if ($punches !== null && $punches['count'] > 0) {
            // Un día antes: la checada de madrugada puede ser la salida del
            // turno nocturno del día anterior.
            RecordAttendance::forEmployee(
                $result['employee']->id,
                $punches['from']->subDay(),
                $punches['to'],
            );
        }

        return [
            'employee' => $result['employee'],
            'devices' => $result['devices'],
            'adopted' => $result['adopted'],
            'punches' => $punches['count'] ?? 0,
        ];
    }

    /**
     * Checadas que el PIN registró antes de existir el expediente: llegaron sin
     * empleado y pasan al trabajador recién creado para que su asistencia las
     * tome en cuenta.
     *
     * @return array{count: int, from: CarbonImmutable|null, to: CarbonImmutable|null}
     */
    private function adoptPunches(Employee $employee): array
    {
        $orphans = AttendancePunch::where('pin', $employee->rfc)->whereNull('employee_id');

        $range = $orphans->clone()
            ->selectRaw('MIN(punched_at) as first_at, MAX(punched_at) as last_at')
            ->first();

        $count = $orphans->update(['employee_id' => $employee->id]);

        return [
            'count' => $count,
            'from' => $range?->first_at ? CarbonImmutable::parse($range->first_at) : null,
            'to' => $range?->last_at ? CarbonImmutable::parse($range->last_at) : null,
        ];
    }

    /**
     * Mensaje para RH sobre el estado de la sincronización.
     *
     * El rostro nunca viaja en el alta: se enrola frente a cada terminal. Sin
     * equipos activos el usuario queda solo en el sistema, y se sincronizará
     * cuando se dé de alta una terminal. Si el usuario ya existía en el
     * checador, el rostro ya está enrolado y solo se actualiza su nombre.
     */
    public function syncMessage(int $devices, bool $adopted = false, int $punches = 0): string
    {
        if ($adopted) {
            $message = 'Trabajador creado y ligado a su usuario del checador, que ya estaba enrolado en el equipo.';

            return $punches === 0
                ? $message
                : "{$message} Se le asociaron {$punches} checada(s) anteriores.";
        }

        return $devices === 0
            ? 'Trabajador creado. No hay terminales activas, así que su alta en el checador quedó pendiente de enviar.'
            : "Trabajador creado y enviado a {$devices} terminal(es). Falta enrolar el rostro en el equipo.";
    }
}
