<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\VacationPeriod;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VacationRequestService
{
    public function __construct(
        private readonly VacationPeriodService $periods,
        private readonly WorkingDayCalculator $workingDays,
    ) {}

    /**
     * Días hábiles del rango y cómo se repartirían entre periodos, sin
     * guardar nada. El frontend lo usa para mostrar el costo antes de enviar.
     *
     * @return array<string, mixed>
     */
    public function preview(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $this->workingDays->between($employee, $from, $to);
        $periods = $this->periods->ensurePeriods($employee);
        $allocation = $this->allocate($days, $periods);

        return [
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'working_days' => $days->count(),
            'calendar_days' => $from->diffInDays($to) + 1,
            'dates' => $days->map(fn (CarbonImmutable $date) => $date->toDateString())->all(),
            'available_days' => round($this->availableDays($periods), 1),
            'has_enough_balance' => $allocation !== null,
            'allocation' => $allocation === null ? [] : collect($allocation)
                ->groupBy('period_id')
                ->map(fn (Collection $group, $periodId) => [
                    'period_id' => (int) $periodId,
                    'year_number' => $periods->firstWhere('id', (int) $periodId)?->year_number,
                    'days' => $group->count(),
                ])->values()->all(),
        ];
    }

    public function create(
        Employee $employee,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $comments,
        ?int $requestedBy,
    ): VacationRequest {
        return DB::transaction(function () use ($employee, $from, $to, $comments, $requestedBy): VacationRequest {
            $this->guardOverlap($employee, $from, $to);

            $days = $this->workingDays->between($employee, $from, $to);

            if ($days->isEmpty()) {
                throw ValidationException::withMessages([
                    'starts_on' => 'El periodo no incluye ningún día laborable para este empleado.',
                ]);
            }

            $periods = $employee->vacationPeriods()->oldestFirst()->lockForUpdate()->get();
            $this->periods->ensurePeriods($employee);
            $periods = $employee->vacationPeriods()->oldestFirst()->get();

            $allocation = $this->allocate($days, $periods);

            if ($allocation === null) {
                throw ValidationException::withMessages([
                    'starts_on' => sprintf(
                        'Saldo insuficiente: la solicitud consume %d día(s) hábil(es) y hay %s disponible(s).',
                        $days->count(),
                        rtrim(rtrim(number_format($this->availableDays($periods), 1), '0'), '.'),
                    ),
                ]);
            }

            $request = $employee->vacationRequests()->create([
                'starts_on' => $from,
                'ends_on' => $to,
                'requested_days' => $days->count(),
                'status' => VacationRequest::PENDING,
                'comments' => $comments,
                'requested_by' => $requestedBy,
            ]);

            foreach ($allocation as $entry) {
                $request->days()->create([
                    'vacation_period_id' => $entry['period_id'],
                    'date' => $entry['date'],
                    'days' => 1,
                ]);
            }

            $this->refreshPeriods($request);

            return $request;
        });
    }

    public function approve(VacationRequest $request, ?int $reviewedBy): VacationRequest
    {
        $this->guardPending($request);

        $request->update([
            'status' => VacationRequest::APPROVED,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
        ]);

        return $request;
    }

    public function reject(VacationRequest $request, ?int $reviewedBy, ?string $reason): VacationRequest
    {
        $this->guardPending($request);

        return DB::transaction(function () use ($request, $reviewedBy, $reason): VacationRequest {
            $request->update([
                'status' => VacationRequest::REJECTED,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->refreshPeriods($request);

            return $request;
        });
    }

    public function cancel(VacationRequest $request, ?int $reviewedBy): VacationRequest
    {
        if (! $request->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden cancelar solicitudes pendientes o aprobadas.',
            ]);
        }

        return DB::transaction(function () use ($request, $reviewedBy): VacationRequest {
            $request->update([
                'status' => VacationRequest::CANCELLED,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
            ]);

            $this->refreshPeriods($request);

            return $request;
        });
    }

    /**
     * Reparte los días entre periodos, del más viejo al más nuevo, para que el
     * saldo no venza mientras se usa el de un año reciente.
     *
     * Devuelve null si el saldo no alcanza.
     *
     * @param  Collection<int, CarbonImmutable>  $days
     * @param  Collection<int, VacationPeriod>  $periods
     * @return list<array{date: CarbonImmutable, period_id: int}>|null
     */
    private function allocate(Collection $days, Collection $periods): ?array
    {
        $balances = $periods
            ->filter(fn (VacationPeriod $period) => $period->is_available)
            ->sortBy('year_number')
            ->mapWithKeys(fn (VacationPeriod $period) => [$period->id => $period->remaining_days]);

        $allocation = [];

        foreach ($days as $date) {
            $periodId = $balances->search(fn (float $remaining) => $remaining >= 1);

            if ($periodId === false) {
                return null;
            }

            $balances[$periodId] -= 1;
            $allocation[] = ['date' => $date, 'period_id' => $periodId];
        }

        return $allocation;
    }

    /**
     * @param  Collection<int, VacationPeriod>  $periods
     */
    private function availableDays(Collection $periods): float
    {
        return $periods
            ->filter(fn (VacationPeriod $period) => $period->is_available)
            ->sum(fn (VacationPeriod $period) => $period->remaining_days);
    }

    private function guardOverlap(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $overlapping = $employee->vacationRequests()
            ->consuming()
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->first();

        if ($overlapping !== null) {
            throw ValidationException::withMessages([
                'starts_on' => sprintf(
                    'Ya hay una solicitud %s del %s al %s que se traslapa.',
                    mb_strtolower($overlapping->status_label),
                    $overlapping->starts_on->toDateString(),
                    $overlapping->ends_on->toDateString(),
                ),
            ]);
        }
    }

    private function guardPending(VacationRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'La solicitud ya fue '.mb_strtolower($request->status_label).'.',
            ]);
        }
    }

    private function refreshPeriods(VacationRequest $request): void
    {
        VacationPeriod::whereIn('id', $request->days()->distinct()->pluck('vacation_period_id'))
            ->get()
            ->each->recalculateTakenDays();
    }
}
