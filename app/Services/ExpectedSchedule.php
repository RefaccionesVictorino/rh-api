<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\ScheduleOverride;
use App\Models\ShiftDay;

/**
 * Horario que se espera de un empleado en una fecha, ya resuelto entre el
 * turno, el festivo y la excepción personal.
 *
 * Quien lo consume no necesita saber de dónde salió: pregunta por la ventana
 * de trabajo y por el origen si quiere explicárselo a RH.
 */
class ExpectedSchedule
{
    public const SOURCE_SHIFT = 'shift';

    public const SOURCE_HOLIDAY = 'holiday';

    public const SOURCE_OVERRIDE = 'override';

    private function __construct(
        public readonly ShiftDay|Holiday|ScheduleOverride|null $schedule,
        public readonly string $source,
        public readonly ?Holiday $holiday,
        public readonly ?ScheduleOverride $override,
    ) {}

    /**
     * La excepción del empleado manda sobre el festivo, y el festivo sobre el
     * turno. Un festivo "normal" no altera el horario: solo consta para pago.
     */
    public static function resolve(
        ?ShiftDay $shiftDay,
        ?Holiday $holiday,
        ?ScheduleOverride $override,
    ): self {
        if ($override !== null) {
            return new self($override, self::SOURCE_OVERRIDE, $holiday, $override);
        }

        if ($holiday !== null && $holiday->replacesShiftHours()) {
            return new self($holiday, self::SOURCE_HOLIDAY, $holiday, null);
        }

        return new self($shiftDay, self::SOURCE_SHIFT, $holiday, null);
    }

    /**
     * Hay algo que esperar ese día, aunque sea descanso. Falso solo cuando el
     * empleado no tiene turno asignado ni excepción ni festivo.
     */
    public function hasShift(): bool
    {
        return $this->schedule !== null;
    }

    public function isRestDay(): bool
    {
        return $this->schedule !== null && $this->schedule->is_rest_day;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function workWindow(): ?array
    {
        return $this->schedule?->workWindow();
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function breakWindow(): ?array
    {
        return $this->schedule?->breakWindow();
    }

    public function crossesMidnight(): bool
    {
        return $this->schedule !== null && $this->schedule->crosses_midnight;
    }

    public function workMinutes(): int
    {
        return $this->schedule?->work_minutes ?? 0;
    }

    public function breakMinutes(): int
    {
        return $this->schedule?->break_minutes ?? 0;
    }

    public function startTime(): ?string
    {
        return $this->schedule === null ? null : substr((string) $this->schedule->start_time, 0, 5);
    }

    public function endTime(): ?string
    {
        return $this->schedule === null ? null : substr((string) $this->schedule->end_time, 0, 5);
    }
}
