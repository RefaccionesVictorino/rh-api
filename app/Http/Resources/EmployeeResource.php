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
            'birth_country' => $this->birth_country,
            'marital_status' => $this->marital_status,
            'birthdate' => $this->birthdate,
            'personal_email' => $this->personal_email,
            'personal_phone' => $this->personal_phone,
            'work_phone' => $this->work_phone,
            'address' => $this->address,
            'municipality' => $this->municipality,
            'postal_code' => $this->postal_code,
            'photo_url' => $this->photo_url,
            'hire_date' => $this->hire_date?->toDateString(),
            'sub_department_id' => $this->sub_department_id,
            'sub_department' => SubDepartmentResource::make($this->whenLoaded('subDepartment')),
            // Nulo cuando el empleado no tiene turno vigente hoy.
            'current_shift' => $this->whenLoaded(
                'currentShiftAssignment',
                fn () => EmployeeShiftResource::make($this->currentShiftAssignment),
            ),
            // PIN con el que checa en el reloj; nulo si RH no lo ha ligado.
            'time_clock_pin' => $this->whenLoaded('timeClockUser', fn () => $this->timeClockUser?->pin),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
