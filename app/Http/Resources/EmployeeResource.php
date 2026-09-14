<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'second_last_name' => $this->second_last_name,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'rfc' => $this->rfc,
            'curp' => $this->curp,
            'nss' => $this->nss,
            'personal_email' => $this->personal_email,
            'personal_phone' => $this->personal_phone,
            'work_phone' => $this->work_phone,
            'municipality' => $this->municipality,
            'hire_date' => $this->hire_date?->toDateString(),
            // Nulo cuando el empleado no tiene turno vigente hoy.
            'current_shift' => $this->whenLoaded(
                'currentShiftAssignment',
                fn () => EmployeeShiftResource::make($this->currentShiftAssignment),
            ),
        ];
    }
}
