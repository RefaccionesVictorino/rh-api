<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexVacationRequestRequest;
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

class VacationRequestController extends Controller
{
    public function __construct(private readonly VacationRequestService $requests) {}

    /** Bandeja de solicitudes, filtrable por estatus, fechas y área. */
    public function index(IndexVacationRequestRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

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
            ->tap(fn (Builder $q) => $this->applySort($q, $request->sortBy(), $request->sortDir()))
            ->paginate($request->perPage())
            ->withQueryString();

        return VacationRequestResource::collection($requests);
    }

    /**
     * El id desempata: sin él, dos solicitudes con la misma fecha cambian de
     * lugar entre páginas y un registro puede repetirse o perderse.
     */
    private function applySort(Builder $query, string $sortBy, string $sortDir): void
    {
        if ($sortBy === 'employee') {
            // El nombre vive en la relación: subconsulta en lugar de join para
            // no duplicar filas ni estorbar a los filtros por whereHas.
            $query->orderBy(
                Employee::select('name')->whereColumn('employees.id', 'vacation_requests.employee_id'),
                $sortDir,
            );
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        $query->orderBy('id', $sortDir);
    }

    public function forEmployee(Employee $employee): AnonymousResourceCollection
    {
        $requests = $employee->vacationRequests()
            ->with(['requestedBy', 'reviewedBy', 'days'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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

    /**
     * Quién más del área del empleado ya tiene vacaciones en esas fechas.
     *
     * El alcance es el departamento completo, no la sub área: en el organigrama
     * casi ninguna sub área tiene padre, así que la cobertura se acomoda entre
     * las sub áreas hermanas del mismo departamento.
     *
     * No condiciona el alta: es criterio para autorizar. Que un compañero o un
     * jefe esté fuera suele bastar para negarlas, pero no siempre, porque la
     * cobertura puede venir de otra sucursal.
     */
    public function branchOverlaps(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);

        $departmentId = $employee->department()?->id;

        // Sin área asignada no hay con quién comparar la cobertura.
        if ($departmentId === null) {
            return VacationRequestResource::collection(collect());
        }

        $overlaps = VacationRequest::query()
            ->with(['employee.subDepartment.department'])
            ->whereIn('status', ['approved', 'pending'])
            ->where('employee_id', '!=', $employee->id)
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereHas('employee', fn (Builder $e) => $e->ofDepartment($departmentId))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return VacationRequestResource::collection($overlaps);
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
