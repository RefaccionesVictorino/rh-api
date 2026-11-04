<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Días que consume un rango de vacaciones.
 *
 * Se cuentan de lunes a sábado, sin importar el turno del empleado: el saldo es
 * el mismo para todos y no depende de cómo esté programado su horario. Solo se
 * descuentan el domingo y los festivos de descanso; un festivo que se trabaja
 * (horario normal o especial) sí consume día.
 */
class WorkingDayCalculator
{
    public function __construct(private readonly HolidayCalendar $holidays) {}

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function between(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $holidays = $this->holidays->between($from, $to);
        $days = collect();

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            if ($this->isCountable($date, $holidays->get($date->toDateString()))) {
                $days->push($date);
            }
        }

        return $days;
    }

    public function count(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->between($employee, $from, $to)->count();
    }

    private function isCountable(CarbonImmutable $date, ?Holiday $holiday): bool
    {
        if ($date->isSunday()) {
            return false;
        }

        return $holiday === null || ! $holiday->is_rest_day;
    }
}
