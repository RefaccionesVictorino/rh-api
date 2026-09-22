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
     * Fecha desde la que el sistema lleva el control. null cuando se generan
     * todos los periodos desde el ingreso.
     */
    public static function startDate(): ?CarbonImmutable
    {
        $configured = config('vacations.start_date');

        return $configured === null || $configured === ''
            ? null
            : CarbonImmutable::parse($configured)->startOfDay();
    }

    /**
     * Genera los periodos hasta la fecha indicada. Con una fecha futura crea
     * también los que todavía no abren, para poder programar vacaciones contra
     * ellos; esos periodos se conservan mientras sigan alineados al ingreso.
     *
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
        $startDate = static::startDate();

        $lastYearNumber = 0;

        // Un periodo se gana al cumplir el año de servicio, así que el año 1
        // abre en el primer aniversario.
        $yearNumber = 1;

        while (true) {
            $dates = $this->datesForYear($hireDate, $yearNumber);

            if ($dates['starts_on']->gt($upTo)) {
                break;
            }

            $period = $existing->get($yearNumber);

            if (
                $startDate !== null
                && $dates['ends_on']->lt($startDate)
                && ($period === null || $period->taken_days == 0)
            ) {
                $period?->delete();
                $yearNumber++;

                continue;
            }

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

        $this->reconcilePeriodsBeyond($existing, $hireDate, $lastYearNumber);

        return $employee->vacationPeriods()->oldestFirst()->get();
    }

    /**
     * Periodos que sobreviven a una fecha de ingreso vieja: su aniversario ya
     * no corresponde al año de servicio que dicen tener. Los que tienen días
     * tomados se conservan a propósito. Un periodo futuro alineado no es
     * huérfano: existe porque hay vacaciones programadas contra él.
     *
     * @return Collection<int, VacationPeriod>
     */
    public function orphanPeriods(Employee $employee): Collection
    {
        if ($employee->hire_date === null) {
            return $employee->vacationPeriods()->oldestFirst()->get();
        }

        $hireDate = CarbonImmutable::parse($employee->hire_date);

        return $employee->vacationPeriods()
            ->oldestFirst()
            ->get()
            ->reject(fn (VacationPeriod $period) => $this->isAligned($period, $hireDate));
    }

    /**
     * Resumen para la ficha del empleado.
     *
     * `available_days` es lo que se puede pedir: solo el periodo en curso.
     * `pending_days` es lo que sobró de años anteriores; no se solicita y la
     * empresa decide si lo paga. `expired_days` es la parte del pendiente que
     * ya prescribió (18 meses).
     *
     * @return array<string, mixed>
     */
    public function summary(Employee $employee): array
    {
        $periods = $this->ensurePeriods($employee);

        $available = $periods->filter(fn (VacationPeriod $period) => $period->is_available);
        $pending = $periods->filter(fn (VacationPeriod $period) => $period->is_pending);
        $expired = $pending->filter(fn (VacationPeriod $period) => $period->is_expired);

        return [
            'years_of_service' => $employee->yearsOfServiceOn(),
            'next_entitlement_days' => VacationEntitlement::daysForYear($employee->yearsOfServiceOn() + 1),
            'available_days' => round($available->sum(fn (VacationPeriod $p) => $p->remaining_days), 1),
            'pending_days' => round($pending->sum(fn (VacationPeriod $p) => $p->remaining_days), 1),
            'expired_days' => round($expired->sum(fn (VacationPeriod $p) => $p->remaining_days), 1),
            'taken_days' => round($periods->sum('taken_days'), 1),
            'granted_days' => round($periods->sum(fn (VacationPeriod $p) => $p->granted_days), 1),
            'periods' => $periods,
        ];
    }

    /**
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable, expires_on: CarbonImmutable}
     */
    private function datesForYear(CarbonImmutable $hireDate, int $yearNumber): array
    {
        $startsOn = $hireDate->addYears($yearNumber);

        return [
            'starts_on' => $startsOn,
            'ends_on' => $startsOn->addYear()->subDay(),
            'expires_on' => $startsOn->addYear()->addMonths(VacationPeriod::MONTHS_TO_EXPIRE)->subDay(),
        ];
    }

    private function isAligned(VacationPeriod $period, CarbonImmutable $hireDate): bool
    {
        return $period->starts_on->isSameDay($hireDate->addYears($period->year_number));
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
     * Los periodos más allá del último generado solo se justifican por tener
     * vacaciones programadas; sin días se van (se vuelven a crear cuando haga
     * falta). Con días, un futuro alineado se mantiene al corriente y un
     * resto de otra fecha de ingreso se conserva para revisión manual.
     *
     * @param  Collection<int, VacationPeriod>  $existing
     */
    private function reconcilePeriodsBeyond(Collection $existing, CarbonImmutable $hireDate, int $lastYearNumber): void
    {
        $existing
            ->filter(fn (VacationPeriod $period) => $period->year_number > $lastYearNumber)
            ->each(function (VacationPeriod $period) use ($hireDate): void {
                if ($period->taken_days == 0) {
                    $period->delete();
                } elseif ($this->isAligned($period, $hireDate)) {
                    $changes = $this->realignedDates($period, $this->datesForYear($hireDate, $period->year_number));

                    if ($changes !== []) {
                        $period->update($changes);
                    }
                }
            });
    }
}
