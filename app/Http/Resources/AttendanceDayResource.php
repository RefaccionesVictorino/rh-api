<?php

namespace App\Http\Resources;

use App\Models\AttendanceDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceDay
 */
class AttendanceDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'date' => $this->date->toDateString(),
            'weekday' => $this->date->dayOfWeek,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'incidents' => $this->incidents,
            'schedule_source' => $this->schedule_source,
            'expected' => $this->expected_start === null ? null : [
                'start' => $this->expected_start,
                'end' => $this->expected_end,
                'work_minutes' => $this->expected_work_minutes,
                'break_minutes' => $this->expected_break_minutes,
            ],
            'first_in' => $this->first_in,
            'last_out' => $this->last_out,
            'break_out' => $this->break_out,
            'break_in' => $this->break_in,
            'late_minutes' => $this->late_minutes,
            'worked_minutes' => $this->worked_minutes,
            'break_minutes' => $this->break_minutes,
            'break_overrun_minutes' => $this->break_overrun_minutes,
            'break_exceeded' => $this->break_exceeded,
            'missing_out' => $this->missing_out,
            'irregular_punches' => $this->irregular_punches,
            'justified' => $this->isJustified() ? [
                'reason' => $this->justification,
                'at' => $this->justified_at,
                'by' => $this->whenLoaded('justifiedBy', fn () => $this->justifiedBy === null ? null : [
                    'id' => $this->justifiedBy->id,
                    'name' => $this->justifiedBy->name,
                ]),
            ] : null,
            'is_locked' => $this->isLocked(),
            'evaluated_at' => $this->evaluated_at,
            'shift' => $this->whenLoaded('shift', fn () => $this->shift === null ? null : [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'code' => $this->shift->code,
            ]),
            'employee' => EmployeeSummaryResource::make($this->whenLoaded('employee')),
            // Solo cuando el listado cargó la rama completa, para agrupar por
            // área sin consultas extra.
            'department' => $this->when(
                $this->relationLoaded('employee') && $this->employee->relationLoaded('subDepartment'),
                fn () => $this->employee->department() === null ? null : [
                    'id' => $this->employee->department()->id,
                    'name' => $this->employee->department()->name,
                    'color' => $this->employee->department()->color,
                ],
            ),
        ];
    }
}
