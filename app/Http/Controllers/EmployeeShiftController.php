<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkAssignShiftRequest;
use App\Http\Requests\StoreEmployeeShiftRequest;
use App\Http\Requests\UpdateEmployeeShiftRequest;
use App\Http\Resources\EmployeeShiftResource;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Shift;
use App\Services\ShiftAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class EmployeeShiftController extends Controller
{
    public function __construct(private readonly ShiftAssignmentService $assignments) {}

    /**
     * Historial de turnos de un empleado, del más reciente al más antiguo.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        $history = $employee->shiftAssignments()
            ->with(['shift', 'assignedBy'])
            ->orderByDesc('starts_on')
            ->get();

        return EmployeeShiftResource::collection($history);
    }

    public function store(StoreEmployeeShiftRequest $request, Employee $employee): JsonResponse
    {
        $assignment = $this->assignments->assign(
            $employee,
            Shift::findOrFail($request->validated('shift_id')),
            Carbon::parse($request->validated('starts_on')),
            $request->validated('ends_on') ? Carbon::parse($request->validated('ends_on')) : null,
            $request->validated('notes'),
            $request->user()?->id,
        );

        return EmployeeShiftResource::make($assignment->load(['shift', 'assignedBy']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Empleados asignados a un turno en una fecha (hoy si no se indica).
     */
    public function forShift(Request $request, Shift $shift): AnonymousResourceCollection
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $assignments = $shift->assignments()
            ->activeOn($data['date'] ?? today())
            ->with('employee')
            ->join('employees', 'employees.id', '=', 'employee_shifts.employee_id')
            ->orderBy('employees.name')
            ->orderBy('employees.last_name')
            ->select('employee_shifts.*')
            ->paginate($data['per_page'] ?? 50)
            ->withQueryString();

        return EmployeeShiftResource::collection($assignments);
    }

    /**
     * Asigna el turno a varios empleados a la vez. Todo o nada.
     */
    public function bulkStore(BulkAssignShiftRequest $request, Shift $shift): JsonResponse
    {
        abort_unless($shift->is_active, 422, 'El turno está inactivo.');

        $assignments = $this->assignments->assignMany(
            $shift,
            array_map('intval', $request->validated('employee_ids')),
            Carbon::parse($request->validated('starts_on')),
            $request->validated('ends_on') ? Carbon::parse($request->validated('ends_on')) : null,
            $request->validated('notes'),
            $request->user()?->id,
        );

        $assignments->each->load('employee');

        return EmployeeShiftResource::collection($assignments)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateEmployeeShiftRequest $request, EmployeeShift $shiftAssignment): EmployeeShiftResource
    {
        $assignment = $this->assignments->update($shiftAssignment, $request->validated());

        return EmployeeShiftResource::make($assignment->load(['shift', 'assignedBy']));
    }

    /**
     * Borrado físico: una asignación capturada por error no es historia que
     * conservar.
     */
    public function destroy(EmployeeShift $shiftAssignment): JsonResponse
    {
        $shiftAssignment->delete();

        return response()->json(status: 204);
    }
}
