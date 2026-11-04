<?php

namespace App\Models;

use App\Models\Concerns\HasWorkWindow;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftDay extends Model
{
    use HasWorkWindow;

    /** Nombres de los días, indexados igual que Carbon::dayOfWeek. */
    public const WEEKDAYS = [
        0 => 'Domingo',
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
    ];

    protected $fillable = [
        'shift_id',
        'weekday',
        'is_rest_day',
        'start_time',
        'end_time',
        'break_start',
        'break_end',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_rest_day' => 'boolean',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    protected function weekdayName(): Attribute
    {
        return Attribute::get(fn (): string => self::WEEKDAYS[$this->weekday]);
    }
}
