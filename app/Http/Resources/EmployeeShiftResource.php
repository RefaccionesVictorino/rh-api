<?php

namespace App\Http\Resources;

use App\Models\EmployeeShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeShift
 */
class EmployeeShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'shift_id' => $this->shift_id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_current' => $this->is_current,
            'notes' => $this->notes,
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'employee' => EmployeeSummaryResource::make($this->whenLoaded('employee')),
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
