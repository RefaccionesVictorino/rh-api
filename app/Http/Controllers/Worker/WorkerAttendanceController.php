<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceRangeRequest;
use App\Services\AttendanceCalendarService;
use Illuminate\Http\JsonResponse;

/**
 * Calendario de asistencia del trabajador en la app: el mismo que ve RH en su
 * ficha, sin las notas internas ni quién capturó o justificó cada cosa.
 */
class WorkerAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceCalendarService $calendar) {}

    public function calendar(AttendanceRangeRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();
        $calendar = $this->calendar->build($request->user()->employee, $from, $to);

        $calendar['days'] = array_map(fn (array $day) => [
            ...$day,
            'justified' => $day['justified'] === null ? null : [
                'reason' => $day['justified']['reason'],
                'at' => $day['justified']['at'],
            ],
            'punches' => array_map(
                fn (array $punch) => array_diff_key($punch, array_flip(['notes', 'created_by'])),
                $day['punches'],
            ),
        ], $calendar['days']);

        return response()->json(['data' => $calendar]);
    }
}
