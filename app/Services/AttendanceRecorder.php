<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Persiste en attendance_days la evaluación de los días ya cerrados.
 *
 * Nunca guarda hoy ni el futuro: el día en curso sigue abierto a checadas y
 * se calcula al vuelo hasta que el cierre nocturno lo registra.
 */
class AttendanceRecorder
{
    public function __construct(private readonly AttendanceCalendarService $calendar) {}

    /**
     * @return Collection<int, AttendanceDay>
     */
    public function record(Employee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = min(CarbonImmutable::instance($to)->startOfDay(), $this->calendar->today()->subDay());

        // Antes del ingreso no hay nada que evaluar.
        if ($employee->hire_date !== null) {
            $from = max($from, CarbonImmutable::instance($employee->hire_date)->startOfDay());
        }

        if ($from->gt($to)) {
            return collect();
        }

        return collect($this->calendar->evaluate($employee, $from, $to))
            ->map(fn (array $day) => AttendanceDay::record($employee, $day));
    }

    /**
     * Toda la plantilla vigente. Devuelve cuántos renglones se escribieron.
     *
     * @param  list<int>|null  $employeeIds  Nulo = todos los empleados activos.
     */
    public function recordAll(CarbonInterface $from, CarbonInterface $to, ?array $employeeIds = null): int
    {
        $written = 0;

        Employee::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->orderBy('id')
            ->chunkById(100, function (Collection $employees) use ($from, $to, &$written): void {
                foreach ($employees as $employee) {
                    $written += $this->record($employee, $from, $to)->count();
                }
            });

        return $written;
    }
}
