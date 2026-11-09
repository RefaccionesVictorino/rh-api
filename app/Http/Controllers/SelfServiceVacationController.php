<?php

namespace App\Http\Controllers;

use App\Http\Requests\IdentifyEmployeeRequest;
use App\Http\Requests\SelfServiceVacationRequest;
use App\Services\VacationRequestService;
use App\Services\WorkerVacationOverview;
use Illuminate\Http\JsonResponse;

/**
 * Portal del trabajador (/mis-vacaciones del frontend): consulta su saldo y
 * pide vacaciones sin cuenta en el sistema. Las solicitudes quedan pendientes
 * y sin `requested_by`, igual que cualquier otra que RH tenga que autorizar.
 */
class SelfServiceVacationController extends Controller
{
    public function __construct(
        private readonly WorkerVacationOverview $overview,
        private readonly VacationRequestService $requests,
    ) {}

    public function show(IdentifyEmployeeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->overview->for($request->employee())]);
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

        return response()->json(['data' => $this->overview->for($employee)], 201);
    }
}
