<?php

namespace App\Http\Resources;

use App\Models\ShiftDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShiftDay
 */
class ShiftDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'weekday' => $this->weekday,
            'weekday_name' => $this->weekday_name,
            'is_rest_day' => $this->is_rest_day,
            'start_time' => self::hhmm($this->start_time),
            'end_time' => self::hhmm($this->end_time),
            'break_start' => self::hhmm($this->break_start),
            'break_end' => self::hhmm($this->break_end),
            'has_break' => $this->has_break,
            'crosses_midnight' => $this->crosses_midnight,
            'break_minutes' => $this->break_minutes,
            'work_minutes' => $this->work_minutes,
        ];
    }

    /**
     * La base devuelve "HH:MM:SS"; la API habla en "HH:MM", igual que recibe.
     */
    private static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
