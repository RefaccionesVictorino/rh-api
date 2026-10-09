<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexAbsenceRequest;
use App\Http\Requests\StoreAbsenceRequest;
use App\Http\Resources\AbsenceResource;
use App\Http\Resources\VacationRequestResource;
use App\Models\Absence;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Services\FileUploader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Incapacidades y permisos.
 *
 * El certificado nunca llega al disco del API: se reenvía al microservicio de
 * carga y en la columna queda la URL que devuelve, igual que la foto del
 * expediente. Quitarlo solo limpia la columna; el objeto permanece en el bucket.
 */
class AbsenceController extends Controller
{
    private const RELATIONS = ['employee.subDepartment.department', 'createdBy'];

    /** Listado general, filtrable por tipo, fechas, área y vigencia. */
    public function index(IndexAbsenceRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $today = CarbonImmutable::now(config('time_clock.timezone'))->startOfDay();

        $absences = Absence::query()
            ->with(self::RELATIONS)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            // Traslape con el rango consultado, no contención.
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('ends_on', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('starts_on', '<=', $v))
            ->when($request->boolean('active'), fn ($q) => $q->activeOn($today))
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

        return AbsenceResource::collection($absences);
    }

    /**
     * El id desempata: sin él, dos ausencias con la misma fecha cambian de
     * lugar entre páginas y un registro puede repetirse o perderse.
     */
    private function applySort(Builder $query, string $sortBy, string $sortDir): void
    {
        if ($sortBy === 'employee') {
            $query->orderBy(
                Employee::select('name')->whereColumn('employees.id', 'absences.employee_id'),
                $sortDir,
            );
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        $query->orderBy('id', $sortDir);
    }

    public function forEmployee(Employee $employee): AnonymousResourceCollection
    {
        $absences = $employee->absences()
            ->with('createdBy')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        return AbsenceResource::collection($absences);
    }

    /**
     * Qué se pisa con el rango antes de guardarlo: vacaciones que apartan
     * saldo y otras ausencias. Las vacaciones no impiden el alta, RH decide;
     * otra ausencia sí la rechaza el alta con 422.
     */
    public function conflicts(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'ignore_id' => [
                'nullable',
                'integer',
                Rule::exists('absences', 'id')->where('employee_id', $employee->id),
            ],
        ]);

        $from = CarbonImmutable::parse($data['starts_on']);
        $to = CarbonImmutable::parse($data['ends_on']);

        $vacations = $employee->vacationRequests()
            ->consuming()
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')
            ->get();

        $absences = $employee->absences()
            ->when($data['ignore_id'] ?? null, fn ($q, $v) => $q->whereKeyNot($v))
            ->overlapping($from, $to)
            ->orderBy('starts_on')
            ->get();

        return response()->json(['data' => [
            'vacation_requests' => VacationRequestResource::collection($vacations)->resolve(),
            'absences' => AbsenceResource::collection($absences)->resolve(),
        ]]);
    }

    public function store(
        StoreAbsenceRequest $request,
        Employee $employee,
        FileUploader $uploader,
    ): JsonResponse {
        $documentUrl = null;

        if ($request->hasFile('document')) {
            try {
                $documentUrl = $this->upload($request, $employee, $uploader);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        $absence = $employee->absences()->create($request->payload() + [
            'document_url' => $documentUrl,
            'created_by' => $request->user()?->id,
        ]);

        return AbsenceResource::make($absence->refresh()->load('createdBy'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * El documento se sube antes de tocar la base: si la subida falla, el
     * registro queda como estaba.
     */
    public function update(
        StoreAbsenceRequest $request,
        Absence $absence,
        FileUploader $uploader,
    ): AbsenceResource|JsonResponse {
        $changes = $request->payload();

        if ($request->hasFile('document')) {
            try {
                $changes['document_url'] = $this->upload($request, $absence->employee, $uploader);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }
        } elseif ($request->boolean('remove_document')) {
            $changes['document_url'] = null;
        }

        $absence->update($changes);

        return AbsenceResource::make($absence->refresh()->load('createdBy'));
    }

    public function destroy(Absence $absence): JsonResponse
    {
        $absence->delete();

        return response()->json(status: 204);
    }

    private function upload(StoreAbsenceRequest $request, Employee $employee, FileUploader $uploader): string
    {
        return $uploader->upload(
            $request->file('document'),
            'empleados/'.$employee->id.'/ausencias/',
        );
    }
}
