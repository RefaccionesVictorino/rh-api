<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkerVacationRequest;
use App\Services\VacationRequestService;
use App\Services\WorkerVacationOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vacaciones del trabajador en la app: lo mismo que el portal web
 * mis-vacaciones, con el empleado tomado de la sesión. Las solicitudes quedan
 * pendientes y sin `requested_by`, como cualquier otra que RH deba autorizar.
 */
class WorkerVacationController extends Controller
{
    public function __construct(
        private readonly WorkerVacationOverview $overview,
        private readonly VacationRequestService $requests,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->overview->for($request->user()->employee)]);
    }

    public function preview(WorkerVacationRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json([
            'data' => $this->requests->preview($request->user()->employee, $from, $to),
        ]);
    }

    public function store(WorkerVacationRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();
        $employee = $request->user()->employee;

        $this->requests->create($employee, $from, $to, $request->validated('comments'), null);

        return response()->json(['data' => $this->overview->for($employee)], 201);
    }
}
