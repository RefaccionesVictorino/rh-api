<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleOverrideRequest;
use App\Http\Resources\ScheduleOverrideResource;
use App\Jobs\RecordAttendance;
use App\Models\Employee;
use App\Models\ScheduleOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleOverrideController extends Controller
{
    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $overrides = $employee->scheduleOverrides()
            ->with('createdBy')
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date')
            ->get();

        return ScheduleOverrideResource::collection($overrides);
    }

    /**
     * Reemplaza la excepción del día si ya existía: RH corrige sobre la marcha
     * y no tiene por qué borrar antes.
     */
    public function store(StoreScheduleOverrideRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->payload();

        // Una excepción borrada seguiría ocupando (employee_id, date) en el índice único.
        $employee->scheduleOverrides()->onlyTrashed()->whereDate('date', $data['date'])->forceDelete();

        $override = $employee->scheduleOverrides()->updateOrCreate(
            ['date' => $data['date']],
            $data + ['created_by' => $request->user()?->id],
        );

        RecordAttendance::forEmployee($employee->id, $override->date, $override->date);

        return ScheduleOverrideResource::make($override->refresh()->load('createdBy'))
            ->response()
            ->setStatusCode($override->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(ScheduleOverride $scheduleOverride): JsonResponse
    {
        $scheduleOverride->delete();

        RecordAttendance::forEmployee($scheduleOverride->employee_id, $scheduleOverride->date, $scheduleOverride->date);

        return response()->json(status: 204);
    }
}
