<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\ScheduleOverride;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Días que un empleado tenía programado laborar en un rango.
 *
 * Se apoya en la misma resolución de horario que la asistencia, de modo que
 * descansos, festivos y excepciones personales no consuman saldo de
 * vacaciones.
 */
class WorkingDayCalculator
{
    public function __construct(private readonly HolidayCalendar $holidays) {}

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function between(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $assignments = $employee->shiftAssignments()
            ->with('shift.days')
            ->overlapping($from, $to)
            ->orderBy('starts_on')
            ->get();

        $holidays = $this->holidays->between($from, $to);

        $overrides = $employee->scheduleOverrides()
            ->betweenDates($from, $to)
            ->get()
            ->keyBy(fn (ScheduleOverride $override) => $override->date->toDateString());

        $days = collect();

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();

            $assignment = $assignments->first(fn (EmployeeShift $item) => $item->coversDate($date));

            $expected = ExpectedSchedule::resolve(
                $assignment?->shift?->dayFor($date->dayOfWeek),
                $holidays->get($key),
                $overrides->get($key),
            );

            if ($expected->hasShift() && ! $expected->isRestDay()) {
                $days->push($date);
            }
        }

        return $days;
    }

    public function count(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->between($employee, $from, $to)->count();
    }
}
