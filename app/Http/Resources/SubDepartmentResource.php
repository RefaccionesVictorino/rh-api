<?php

namespace App\Http\Resources;

use App\Models\SubDepartment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubDepartment
 */
class SubDepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'department_id' => $this->department_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'manager' => new EmployeeSummaryResource($this->whenLoaded('manager')),
            'children' => SubDepartmentResource::collection($this->whenLoaded('children')),
            // Cuántas sub áreas cuelgan de esta: el organigrama lo usa para
            // saber si pintar el control de desplegar antes de pedirlas.
            'children_count' => $this->whenCounted('children'),
            'employees_count' => $this->whenCounted('employees'),
        ];
    }
}
