<?php

namespace App\Http\Resources;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'name' => $this->name,
            'observance' => $this->observance,
            'observance_label' => $this->observance_label,
            'is_mandatory' => $this->is_mandatory,
            'notes' => $this->notes,
            'start_time' => self::hhmm($this->start_time),
            'end_time' => self::hhmm($this->end_time),
            'break_start' => self::hhmm($this->break_start),
            'break_end' => self::hhmm($this->break_end),
            'work_minutes' => $this->work_minutes,
            'crosses_midnight' => $this->crosses_midnight,
            'holiday_rule_id' => $this->holiday_rule_id,
            // Un día generado por el catálogo no tiene id: se edita creando la
            // excepción de ese año.
            'is_generated' => $this->id === null,
            'rule_name' => $this->whenLoaded('rule', fn () => $this->rule->name),
        ];
    }

    private static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
