<?php

namespace App\Http\Resources;

use App\Models\VacationPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VacationPeriod
 */
class VacationPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'year_number' => $this->year_number,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'expires_on' => $this->expires_on->toDateString(),
            'entitled_days' => $this->entitled_days,
            'adjustment_days' => $this->adjustment_days,
            'adjustment_reason' => $this->adjustment_reason,
            'granted_days' => $this->granted_days,
            'taken_days' => $this->taken_days,
            'remaining_days' => $this->remaining_days,
            'is_current' => $this->is_current,
            'is_available' => $this->is_available,
            'is_pending' => $this->is_pending,
            'is_expired' => $this->is_expired,
            'is_future' => $this->is_future,
        ];
    }
}
