<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceRangeRequest;
use App\Models\Employee;
use App\Services\AttendanceCalendarService;
use Illuminate\Http\JsonResponse;

class EmployeeAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceCalendarService $calendar) {}

    /**
     * Calendario de asistencia de un empleado: un renglón por día con su
     * estado, horas y checadas, más el resumen del rango.
     */
    public function calendar(AttendanceRangeRequest $request, Employee $employee): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json(['data' => $this->calendar->build($employee, $from, $to)]);
    }
}
