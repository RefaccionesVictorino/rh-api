<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\VacationEntitlement;
use App\Models\VacationPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Genera y mantiene al día los periodos vacacionales de un empleado.
 *
 * Cada aniversario de la fecha de ingreso abre un periodo con los días que
 * marca el tabulador. Es idempotente: se puede correr cuantas veces haga falta
 * sin duplicar ni pisar los ajustes que RH haya hecho.
 */
class VacationPeriodService
{
    /**
     * @return Collection<int, VacationPeriod>
     */
    public function ensurePeriods(Employee $employee, ?CarbonImmutable $upTo = null): Collection
    {
        if ($employee->hire_date === null) {
            return collect();
        }

        $upTo ??= CarbonImmutable::today();
        $hireDate = CarbonImmutable::parse($employee->hire_date);
        $existing = $employee->vacationPeriods()->get()->keyBy('year_number');

        // Un periodo se gana al cumplir el año de servicio, así que el año 1
        // abre en el primer aniversario.
        $yearNumber = 1;

        while (true) {
            $startsOn = $hireDate->addYears($yearNumber);

            if ($startsOn->gt($upTo)) {
                break;
            }

            $period = $existing->get($yearNumber);

            if ($period === null) {
                $employee->vacationPeriods()->create([
                    'year_number' => $yearNumber,
                    'starts_on' => $startsOn,
                    'ends_on' => $startsOn->addYear()->subDay(),
                    'expires_on' => $startsOn->addYear()->addMonths(VacationPeriod::MONTHS_TO_EXPIRE)->subDay(),
                    'entitled_days' => VacationEntitlement::daysForYear($yearNumber),
                ]);
            } elseif ($period->taken_days == 0) {
                // Un cambio en el tabulador se refleja mientras nadie haya
                // tomado días de ese periodo.
                $entitled = VacationEntitlement::daysForYear($yearNumber);

                if ($period->entitled_days !== $entitled) {
                    $period->update(['entitled_days' => $entitled]);
                }
            }

            $yearNumber++;
        }

        return $employee->vacationPeriods()->oldestFirst()->get();
    }

    /**
     * Resumen para la ficha del empleado: saldo disponible, vencido y futuro.
     *
     * @return array<string, mixed>
     */
    public function summary(Employee $employee): array
    {
        $periods = $this->ensurePeriods($employee);

        $available = $periods->filter(fn (VacationPeriod $period) => $period->is_available);
        $expired = $periods->filter(
            fn (VacationPeriod $period) => $period->is_expired && $period->remaining_days > 0
        );

        return [
            'years_of_service' => $employee->yearsOfServiceOn(),
            'next_entitlement_days' => VacationEntitlement::daysForYear($employee->yearsOfServiceOn() + 1),
            'available_days' => round($available->sum(fn (VacationPeriod $p) => $p->remaining_days), 1),
            'expired_days' => round($expired->sum(fn (VacationPeriod $p) => $p->remaining_days), 1),
            'taken_days' => round($periods->sum('taken_days'), 1),
            'granted_days' => round($periods->sum(fn (VacationPeriod $p) => $p->granted_days), 1),
            'periods' => $periods,
        ];
    }
}
