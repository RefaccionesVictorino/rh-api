<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versión mínima del empleado, para incrustarlo como responsable de un área sin
 * exponer RFC, CURP, NSS ni domicilio en un listado de organigrama.
 *
 * @mixin Employee
 */
class EmployeeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'work_phone' => $this->work_phone,
            'photo_url' => $this->photo_url,
        ];
    }
}
