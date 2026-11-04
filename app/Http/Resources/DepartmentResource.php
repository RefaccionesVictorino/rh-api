<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'manager' => new EmployeeSummaryResource($this->whenLoaded('manager')),
            'sub_departments' => SubDepartmentResource::collection($this->whenLoaded('subDepartments')),
            // Solo presentes cuando el controlador pidió los conteos.
            'sub_departments_count' => $this->whenCounted('subDepartments'),
            'employees_count' => $this->whenCounted('employees'),
        ];
    }
}
