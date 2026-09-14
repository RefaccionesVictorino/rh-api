<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftDay extends Model
{
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

    public const MINUTES_PER_DAY = 1440;

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

    /**
     * Convierte "HH:MM" o "HH:MM:SS" a minutos desde medianoche.
     */
    public static function toMinutes(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * Ventana de trabajo en minutos [inicio, fin] desde la medianoche del día
     * del turno. Si cruza medianoche, el fin queda por encima de 1440.
     *
     * @return array{0: int, 1: int}|null
     */
    public function workWindow(): ?array
    {
        if ($this->is_rest_day || $this->start_time === null || $this->end_time === null) {
            return null;
        }

        $start = self::toMinutes($this->start_time);
        $end = self::toMinutes($this->end_time);

        if ($end <= $start) {
            $end += self::MINUTES_PER_DAY;
        }

        return [$start, $end];
    }

    /**
     * Ventana de comida en minutos, en el mismo eje que workWindow(): una
     * comida después de medianoche en un turno nocturno queda por encima de
     * 1440. Nulo en turno corrido.
     *
     * @return array{0: int, 1: int}|null
     */
    public function breakWindow(): ?array
    {
        $work = $this->workWindow();

        if ($work === null || $this->break_start === null || $this->break_end === null) {
            return null;
        }

        $start = self::toMinutes($this->break_start);
        $end = self::toMinutes($this->break_end);

        if ($start < $work[0]) {
            $start += self::MINUTES_PER_DAY;
        }

        if ($end <= $start) {
            $end += self::MINUTES_PER_DAY;
        }

        return [$start, $end];
    }

    protected function hasBreak(): Attribute
    {
        return Attribute::get(fn (): bool => $this->breakWindow() !== null);
    }

    protected function crossesMidnight(): Attribute
    {
        return Attribute::get(function (): bool {
            $work = $this->workWindow();

            return $work !== null && $work[1] > self::MINUTES_PER_DAY;
        });
    }

    protected function breakMinutes(): Attribute
    {
        return Attribute::get(function (): int {
            $break = $this->breakWindow();

            return $break === null ? 0 : $break[1] - $break[0];
        });
    }

    /** Minutos de jornada esperados, ya descontada la comida. */
    protected function workMinutes(): Attribute
    {
        return Attribute::get(function (): int {
            $work = $this->workWindow();

            return $work === null ? 0 : ($work[1] - $work[0]) - $this->break_minutes;
        });
    }

    protected function weekdayName(): Attribute
    {
        return Attribute::get(fn (): string => self::WEEKDAYS[$this->weekday]);
    }
}
