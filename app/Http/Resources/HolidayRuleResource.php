<?php

namespace App\Http\Resources;

use App\Models\HolidayRule;
use App\Models\ShiftDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HolidayRule
 */
class HolidayRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rule_type' => $this->rule_type,
            'rule_type_label' => HolidayRule::RULE_TYPES[$this->rule_type] ?? $this->rule_type,
            'month' => $this->month,
            'day' => $this->day,
            'weekday' => $this->weekday,
            'weekday_name' => $this->weekday === null ? null : ShiftDay::WEEKDAYS[$this->weekday],
            'week_of_month' => $this->week_of_month,
            'observance' => $this->observance,
            'observance_label' => $this->observance_label,
            'start_time' => self::hhmm($this->start_time),
            'end_time' => self::hhmm($this->end_time),
            'break_start' => self::hhmm($this->break_start),
            'break_end' => self::hhmm($this->break_end),
            'is_mandatory' => $this->is_mandatory,
            'is_active' => $this->is_active,
            'starts_year' => $this->starts_year,
            'ends_year' => $this->ends_year,
            'notes' => $this->notes,
            // Ayuda a RH a confirmar que la regla cae donde espera.
            'next_dates' => collect(range((int) now()->year, (int) now()->year + 2))
                ->map(fn (int $year) => $this->dateFor($year)?->toDateString())
                ->filter()
                ->values(),
        ];
    }

    private static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
