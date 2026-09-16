<?php

namespace App\Http\Resources;

use App\Models\ScheduleOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduleOverride
 */
class ScheduleOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'date' => $this->date->toDateString(),
            'is_rest_day' => $this->is_rest_day,
            'start_time' => self::hhmm($this->start_time),
            'end_time' => self::hhmm($this->end_time),
            'break_start' => self::hhmm($this->break_start),
            'break_end' => self::hhmm($this->break_end),
            'work_minutes' => $this->work_minutes,
            'crosses_midnight' => $this->crosses_midnight,
            'reason' => $this->reason,
            'employee' => EmployeeSummaryResource::make($this->whenLoaded('employee')),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
        ];
    }

    private static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
