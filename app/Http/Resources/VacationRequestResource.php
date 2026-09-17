<?php

namespace App\Http\Resources;

use App\Models\VacationRequest;
use App\Models\VacationRequestDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VacationRequest
 */
class VacationRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'requested_days' => $this->requested_days,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'comments' => $this->comments,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at,
            'employee' => EmployeeSummaryResource::make($this->whenLoaded('employee')),
            // Solo cuando el listado cargó la rama completa; el calendario lo
            // usa para agrupar por área sin una consulta por solicitud.
            'department' => $this->when(
                $this->relationLoaded('employee') && $this->employee->relationLoaded('subDepartment'),
                fn () => $this->employee->department() === null ? null : [
                    'id' => $this->employee->department()->id,
                    'name' => $this->employee->department()->name,
                    'color' => $this->employee->department()->color,
                ],
            ),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ]),
            'dates' => $this->whenLoaded(
                'days',
                fn () => $this->days->map(fn (VacationRequestDay $day) => $day->date->toDateString())->all(),
            ),
        ];
    }
}
