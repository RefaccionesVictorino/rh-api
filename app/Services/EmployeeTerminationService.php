<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Models\TimeClockDevice;
use App\Services\TimeClock\ZkCommandBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Baja y reactivación de un trabajador.
 *
 * La baja es lógica: el expediente, sus checadas y su historial se conservan
 * para consulta y auditoría. Lo que se corta es todo lo que lo mantenía
 * operando: el turno vigente, la jefatura de áreas, el checador, sus sesiones
 * en la app móvil y, a través de EnsureSystemAccess, la cuenta de acceso al
 * sistema si tenía una.
 */
class EmployeeTerminationService
{
    public function __construct(private readonly ZkCommandBuilder $commands) {}

    /**
     * @return int Terminales a las que se encoló el borrado del usuario.
     */
    public function terminate(Employee $employee, string $reason, ?int $terminatedBy): int
    {
        return DB::transaction(function () use ($employee, $reason, $terminatedBy): int {
            $today = today();

            // Un turno abierto bloquearía la baja del turno (ver scopeActiveOrFuture).
            $employee->shiftAssignments()
                ->activeOrFuture()
                ->whereDate('starts_on', '<=', $today->toDateString())
                ->update(['ends_on' => $today]);
            $employee->shiftAssignments()
                ->whereDate('starts_on', '>', $today->toDateString())
                ->delete();

            // El borrado lógico no dispara el nullOnDelete de las llaves.
            Department::where('manager_id', $employee->id)->update(['manager_id' => null]);
            SubDepartment::where('manager_id', $employee->id)->update(['manager_id' => null]);

            $devices = collect();
            $timeClockUser = $employee->timeClockUser;

            // Se desactiva y no se borra: conserva el PIN ligado al expediente
            // y syncUsers ya no lo reenvía a terminales que se reactiven.
            if ($timeClockUser !== null) {
                $timeClockUser->update(['is_active' => false]);

                $devices = TimeClockDevice::where('is_active', true)->get();
                $devices->each(fn (TimeClockDevice $device) => $this->commands->deleteUser($device, $timeClockUser->pin));
            }

            // El NIP se conserva: si lo reactivan, vuelve a entrar con el suyo.
            $employee->workerAccount?->revokeSessions();

            $employee->update([
                'termination_reason' => $reason,
                'terminated_by' => $terminatedBy,
            ]);
            $employee->delete();

            return $devices->count();
        });
    }

    /**
     * Turno y jefaturas no se restauran: pudieron cambiar mientras estuvo de
     * baja, así que RH los vuelve a asignar.
     *
     * @return int Terminales a las que se encoló el alta del usuario.
     */
    public function restore(Employee $employee): int
    {
        return DB::transaction(function () use ($employee): int {
            // restore() guarda con save(), así que limpia la baja en la misma escritura.
            $employee->fill(['termination_reason' => null, 'terminated_by' => null])->restore();

            $devices = collect();
            $timeClockUser = $employee->timeClockUser;

            if ($timeClockUser !== null) {
                $timeClockUser->update(['is_active' => true]);

                $devices = TimeClockDevice::where('is_active', true)->get();
                $devices->each(fn (TimeClockDevice $device) => $this->commands->upsertUser($device, $timeClockUser));
            }

            return $devices->count();
        });
    }

    public function terminationMessage(int $devices): string
    {
        return $devices === 0
            ? 'Trabajador dado de baja. No había terminales activas a las cuales enviar su retiro del checador.'
            : "Trabajador dado de baja y retirado de {$devices} terminal(es).";
    }

    /** La baja borró su rostro de los equipos; hay que enrolarlo de nuevo. */
    public function restoreMessage(int $devices): string
    {
        return $devices === 0
            ? 'Trabajador reactivado. No hay terminales activas, así que su alta en el checador quedó pendiente de enviar. Asígnale un turno.'
            : "Trabajador reactivado y enviado a {$devices} terminal(es). Asígnale un turno y vuelve a enrolar su rostro en el equipo.";
    }
}
