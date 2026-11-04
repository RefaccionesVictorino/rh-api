<?php

namespace App\Http\Controllers;

use App\Http\Requests\IdentifyEmployeeRequest;
use App\Http\Requests\SelfServiceVacationRequest;
use App\Http\Resources\VacationRequestResource;
use App\Models\Employee;
use App\Models\VacationPeriod;
use App\Services\VacationPeriodService;
use App\Services\VacationRequestService;
use Illuminate\Http\JsonResponse;

/**
 * Portal del trabajador (/mis-vacaciones del frontend): consulta su saldo y
 * pide vacaciones sin cuenta en el sistema. Las solicitudes quedan pendientes
 * y sin `requested_by`, igual que cualquier otra que RH tenga que autorizar.
 */
class SelfServiceVacationController extends Controller
{
    /** Solicitudes que se le muestran; las más antiguas ya no le sirven de nada. */
    private const RECENT_REQUESTS = 10;

    public function __construct(
        private readonly VacationPeriodService $periods,
        private readonly VacationRequestService $requests,
    ) {}

    public function show(IdentifyEmployeeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->overview($request->employee())]);
    }

    public function preview(SelfServiceVacationRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json([
            'data' => $this->requests->preview($request->employee(), $from, $to),
        ]);
    }

    public function store(SelfServiceVacationRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();
        $employee = $request->employee();

        $this->requests->create($employee, $from, $to, $request->validated('comments'), null);

        return response()->json(['data' => $this->overview($employee)], 201);
    }

    /**
     * Solo lo que el trabajador necesita para decidir fechas; el desglose por
     * periodo y los datos del expediente se quedan en el panel de RH.
     *
     * @return array<string, mixed>
     */
    private function overview(Employee $employee): array
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
