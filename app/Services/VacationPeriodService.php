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
 *
 * La fecha de ingreso manda: si se corrige, los periodos se realinean a los
 * aniversarios nuevos y los sobrantes se eliminan. Un periodo con días tomados
 * nunca se borra, para no desaparecer vacaciones ya disfrutadas sin que nadie
 * se entere; queda huérfano y se reporta.
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

        $lastYearNumber = 0;

        // Un periodo se gana al cumplir el año de servicio, así que el año 1
        // abre en el primer aniversario.
        $yearNumber = 1;

        while (true) {
            $startsOn = $hireDate->addYears($yearNumber);

            if ($startsOn->gt($upTo)) {
                break;
            }

            $period = $existing->get($yearNumber);
            $dates = [
                'starts_on' => $startsOn,
                'ends_on' => $startsOn->addYear()->subDay(),
                'expires_on' => $startsOn->addYear()->addMonths(VacationPeriod::MONTHS_TO_EXPIRE)->subDay(),
            ];

            if ($period === null) {
                $employee->vacationPeriods()->create($dates + [
                    'year_number' => $yearNumber,
                    'entitled_days' => VacationEntitlement::daysForYear($yearNumber),
                ]);
            } else {
                $changes = $this->realignedDates($period, $dates);

                if ($period->taken_days == 0) {
                    // Un cambio en el tabulador se refleja mientras nadie haya
                    // tomado días de ese periodo.
                    $entitled = VacationEntitlement::daysForYear($yearNumber);

                    if ($period->entitled_days !== $entitled) {
                        $changes['entitled_days'] = $entitled;
                    }
                }

                if ($changes !== []) {
                    $period->update($changes);
                }
            }

            $lastYearNumber = $yearNumber;
            $yearNumber++;
        }

        $this->discardPeriodsBeyond($employee, $existing, $lastYearNumber);

        return $employee->vacationPeriods()->oldestFirst()->get();
    }

    /**
     * Periodos que sobreviven a una fecha de ingreso vieja: ya no corresponden
     * a ningún año de servicio y deben desaparecer. Los que tienen días tomados
     * se conservan a propósito.
     *
     * @return Collection<int, VacationPeriod>
     */
    public function orphanPeriods(Employee $employee, ?CarbonImmutable $upTo = null): Collection
    {
        if ($employee->hire_date === null) {
            return $employee->vacationPeriods()->oldestFirst()->get();
        }

        $upTo ??= CarbonImmutable::today();
        $hireDate = CarbonImmutable::parse($employee->hire_date);
        $yearsEarned = 0;

        while ($hireDate->addYears($yearsEarned + 1)->lte($upTo)) {
            $yearsEarned++;
        }

        return $employee->vacationPeriods()
            ->where('year_number', '>', $yearsEarned)
            ->oldestFirst()
            ->get();
    }

    /**
     * @param  array<string, CarbonImmutable>  $dates
     * @return array<string, CarbonImmutable>
     */
    private function realignedDates(VacationPeriod $period, array $dates): array
    {
        return collect($dates)
            ->reject(fn (CarbonImmutable $date, string $column) => $period->{$column}->isSameDay($date))
            ->all();
    }

    /**
     * @param  Collection<int, VacationPeriod>  $existing
     */
    private function discardPeriodsBeyond(Employee $employee, Collection $existing, int $lastYearNumber): void
    {
        $existing
            ->filter(fn (VacationPeriod $period) => $period->year_number > $lastYearNumber)
            ->filter(fn (VacationPeriod $period) => $period->taken_days == 0)
            ->each->delete();
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
