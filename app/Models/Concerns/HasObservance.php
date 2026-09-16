<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Modalidad de un día festivo y su horario, compartida entre la regla anual y
 * la fecha concreta.
 */
trait HasObservance
{
    /** Nadie trabaja. */
    public const REST = 'rest';

    /** Se trabaja con el horario del festivo, no con el del turno. */
    public const SPECIAL_HOURS = 'special_hours';

    /** Se trabaja con el horario del turno; el festivo solo consta para pago. */
    public const NORMAL = 'normal';

    public const OBSERVANCES = [
        self::REST => 'Descanso',
        self::SPECIAL_HOURS => 'Horario especial',
        self::NORMAL => 'Se trabaja normal',
    ];

    /** HasWorkWindow lo consulta; un festivo de descanso no tiene jornada. */
    protected function isRestDay(): Attribute
    {
        return Attribute::get(fn (): bool => $this->observance === self::REST);
    }

    protected function observanceLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::OBSERVANCES[$this->observance] ?? $this->observance);
    }

    /** Un festivo normal no altera el horario: el turno sigue mandando. */
    public function replacesShiftHours(): bool
    {
        return $this->observance !== self::NORMAL;
    }
}
