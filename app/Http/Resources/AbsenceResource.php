<?php

namespace App\Http\Resources;

use App\Models\Absence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Absence
 */
class AbsenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'is_incapacity' => $this->isIncapacity(),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'calendar_days' => $this->calendar_days,
            'folio' => $this->folio,
            'issued_on' => $this->issued_on?->toDateString(),
            'notes' => $this->notes,
            'document_url' => $this->signed_document_url,
            'created_at' => $this->created_at,
            'employee' => EmployeeSummaryResource::make($this->whenLoaded('employee')),
            // Igual que en VacationRequestResource: solo cuando el listado
            // cargó la rama completa, para agrupar por área sin consultas extra.
            'department' => $this->when(
                $this->relationLoaded('employee') && $this->employee->relationLoaded('subDepartment'),
                fn () => $this->employee->department() === null ? null : [
                    'id' => $this->employee->department()->id,
                    'name' => $this->employee->department()->name,
                    'color' => $this->employee->department()->color,
                ],
            ),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
        ];
    }
}
