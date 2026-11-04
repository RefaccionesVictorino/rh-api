<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Horario de un día expresado en minutos desde su medianoche.
 *
 * Un turno que cruza medianoche termina por encima de 1440, de modo que la
 * salida y la comida de madrugada se comparan en el mismo eje que la entrada.
 */
trait HasWorkWindow
{
    public const MINUTES_PER_DAY = 1440;

    public static function toMinutes(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
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

    /** Jornada esperada ya descontada la comida. */
    protected function workMinutes(): Attribute
    {
        return Attribute::get(function (): int {
            $work = $this->workWindow();

            return $work === null ? 0 : ($work[1] - $work[0]) - $this->break_minutes;
        });
    }
}
