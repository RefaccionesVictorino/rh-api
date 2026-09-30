<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Uno solo: el módulo asigna un rol por usuario.
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'employee_id' => $this->employee_id,
            'employee' => EmployeeSummaryResource::make($this->whenLoaded('employee')),
            'employee_is_active' => $this->whenLoaded('employee', fn () => $this->employee !== null && ! $this->employee->trashed()),
            'has_access' => $this->when(
                $this->relationLoaded('employee') && $this->relationLoaded('roles'),
                fn () => $this->hasSystemAccess(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
