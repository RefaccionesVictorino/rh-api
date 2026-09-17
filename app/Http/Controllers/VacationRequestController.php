<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVacationRequestRequest;
use App\Http\Resources\VacationRequestResource;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Models\VacationRequest;
use App\Services\VacationRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VacationRequestController extends Controller
{
    public function __construct(private readonly VacationRequestService $requests) {}

    /** Bandeja de solicitudes, filtrable por estatus, fechas y área. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(VacationRequest::STATUSES))],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sub_department_id' => ['nullable', 'integer', 'exists:sub_departments,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $requests = VacationRequest::query()
            ->with(['employee.subDepartment.department', 'requestedBy', 'reviewedBy'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            // Traslape con el rango consultado, no contención: una solicitud
            // que empieza antes y termina dentro también interesa.
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('ends_on', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('starts_on', '<=', $v))
            ->when($filters['sub_department_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->whereIn('sub_department_id', SubDepartment::descendantIds($v)),
            ))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->ofDepartment($v),
            ))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->search($v),
            ))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return VacationRequestResource::collection($requests);
    }

    public function forEmployee(Employee $employee): AnonymousResourceCollection
    {
        $requests = $employee->vacationRequests()
            ->with(['requestedBy', 'reviewedBy', 'days'])
            ->orderByDesc('starts_on')
            ->get();

        return VacationRequestResource::collection($requests);
    }

    /**
     * Costo en días hábiles del rango, antes de enviar la solicitud.
     */
    public function preview(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);

        return response()->json([
            'data' => $this->requests->preview(
                $employee,
                CarbonImmutable::parse($data['starts_on']),
                CarbonImmutable::parse($data['ends_on']),
            ),
        ]);
    }

    public function store(StoreVacationRequestRequest $request, Employee $employee): JsonResponse
    {
        [$from, $to] = $request->range();

        $vacationRequest = $this->requests->create(
            $employee,
            $from,
            $to,
            $request->validated('comments'),
            $request->user()?->id,
        );

        return VacationRequestResource::make(
            $vacationRequest->load(['employee', 'requestedBy', 'days'])
        )->response()->setStatusCode(201);
    }

    public function approve(Request $request, VacationRequest $vacationRequest): VacationRequestResource
    {
        $this->requests->approve($vacationRequest, $request->user()?->id);

        return VacationRequestResource::make(
            $vacationRequest->refresh()->load(['employee', 'reviewedBy', 'days'])
        );
    }

    public function reject(Request $request, VacationRequest $vacationRequest): VacationRequestResource
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:255'],
        ]);

        $this->requests->reject($vacationRequest, $request->user()?->id, $data['rejection_reason']);

        return VacationRequestResource::make(
            $vacationRequest->refresh()->load(['employee', 'reviewedBy'])
        );
    }

    public function cancel(Request $request, VacationRequest $vacationRequest): VacationRequestResource
    {
        $this->requests->cancel($vacationRequest, $request->user()?->id);

        return VacationRequestResource::make(
            $vacationRequest->refresh()->load(['employee', 'reviewedBy'])
        );
    }
}
