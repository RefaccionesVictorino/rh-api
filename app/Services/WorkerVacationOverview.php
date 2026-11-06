<?php

namespace App\Services;

use App\Http\Requests\SelfServiceVacationRequest;
use App\Http\Resources\VacationRequestResource;
use App\Models\Employee;
use App\Models\VacationPeriod;

/**
 * Lo que el trabajador ve de sus vacaciones, en el portal web y en la app:
 * solo lo necesario para decidir fechas. El desglose por periodo y los datos
 * del expediente se quedan en el panel de RH.
 */
class WorkerVacationOverview
{
    /** Solicitudes que se le muestran; las más antiguas ya no le sirven de nada. */
    public const RECENT_REQUESTS = 10;

    public function __construct(private readonly VacationPeriodService $periods) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Employee $employee): array
    {
        $summary = $this->periods->summary($employee);
        $current = $summary['periods']->first(fn (VacationPeriod $period) => $period->is_current);

        return [
            'employee' => [
                'name' => $employee->name,
                'full_name' => $employee->full_name,
            ],
            'balance' => [
                'available_days' => $summary['available_days'],
                'years_of_service' => $summary['years_of_service'],
                'current_period' => $current === null ? null : [
                    'starts_on' => $current->starts_on->toDateString(),
                    'ends_on' => $current->ends_on->toDateString(),
                    'granted_days' => $current->granted_days,
                    'taken_days' => $current->taken_days,
                ],
                'next_period_starts_on' => $employee->hire_date
                    ?->copy()->addYears($summary['years_of_service'] + 1)->toDateString(),
                'next_entitlement_days' => $summary['next_entitlement_days'],
            ],
            'earliest_start_on' => SelfServiceVacationRequest::earliestStartDate()->toDateString(),
            'requests' => VacationRequestResource::collection(
                $employee->vacationRequests()
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(self::RECENT_REQUESTS)
                    ->get(),
            ),
        ];
    }
}
