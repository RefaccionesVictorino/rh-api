<?php

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shift
 */
class ShiftResource extends JsonResource
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
            'tolerance_minutes' => $this->tolerance_minutes,
            'absence_after_minutes' => $this->absence_after_minutes,
            'is_active' => $this->is_active,
            'days' => ShiftDayResource::collection($this->whenLoaded('days')),
            // Resumen para el listado: cuántos días se trabaja y cuántos
            // minutos suma la semana, sin que el frontend recorra los días.
            'working_days_count' => $this->whenLoaded('days', fn () => $this->days->where('is_rest_day', false)->count()),
            'weekly_minutes' => $this->whenLoaded('days', fn () => $this->days->sum('work_minutes')),
            'current_employees_count' => $this->whenCounted('currentAssignments'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
