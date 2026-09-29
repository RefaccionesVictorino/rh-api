<?php

namespace App\Http\Controllers\TimeClock;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreManualPunchRequest;
use App\Models\AttendancePunch;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

/**
 * Checadas extraoficiales: las que el empleado olvidó o registró mal en el
 * equipo. Cuentan igual que las del checador para la asistencia; lo que las
 * distingue es el origen manual, quién las capturó y el motivo.
 */
class ManualPunchController extends Controller
{
    public function store(StoreManualPunchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::with('timeClockUser:id,employee_id,pin')->findOrFail($data['employee_id']);

        $punch = AttendancePunch::create([
            'employee_id' => $employee->id,
            // Mismo PIN que usaría en el equipo, para que el filtro por PIN la encuentre.
            'pin' => $employee->timeClockUser?->pin ?? $employee->rfc,
            'punched_at' => $data['punched_at'].':00',
            'punch_type' => $data['punch_type'],
            'source' => AttendancePunch::SOURCE_MANUAL,
            'created_by' => $request->user()?->id,
            'notes' => $data['notes'],
        ]);

        return response()->json([
            'data' => [
                'id' => $punch->id,
                'employee_id' => $punch->employee_id,
                'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                'punch_type' => $punch->punch_type,
                'punch_type_label' => $punch->type_label,
                'source' => $punch->source,
                'notes' => $punch->notes,
                'created_by' => $request->user()?->only(['id', 'name']),
            ],
        ], 201);
    }
}
