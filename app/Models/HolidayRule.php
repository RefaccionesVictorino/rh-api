<?php

namespace App\Models;

use App\Models\Concerns\HasObservance;
use App\Models\Concerns\HasWorkWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Festivo que se repite cada año. La fecha no se guarda: se calcula para el
 * año que se consulte, de modo que el catálogo no caduca.
 */
class HolidayRule extends Model
{
    use HasObservance, HasWorkWindow;

    /** Mismo día y mes todos los años. */
    public const FIXED = 'fixed';

    /** N-ésimo día de la semana del mes, como los feriados movibles de la ley. */
    public const NTH_WEEKDAY = 'nth_weekday';

    public const RULE_TYPES = [
        self::FIXED => 'Fecha fija',
        self::NTH_WEEKDAY => 'Día de la semana del mes',
    ];

    protected $fillable = [
        'name',
        'rule_type',
        'month',
        'day',
        'weekday',
        'week_of_month',
        'observance',
        'start_time',
        'end_time',
        'break_start',
        'break_end',
        'is_mandatory',
        'is_active',
        'starts_year',
        'ends_year',
        'notes',
    ];

    protected $attributes = [
        'rule_type' => self::FIXED,
        'observance' => self::REST,
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'day' => 'integer',
            'weekday' => 'integer',
            'week_of_month' => 'integer',
            'starts_year' => 'integer',
            'ends_year' => 'integer',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Fechas concretas que alguien capturó para pisar esta regla en un año. */
    public function exceptions(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function appliesToYear(int $year): bool
    {
        return ($this->starts_year === null || $year >= $this->starts_year)
            && ($this->ends_year === null || $year <= $this->ends_year);
    }

    public function dateFor(int $year): ?CarbonImmutable
    {
        if (! $this->appliesToYear($year)) {
            return null;
        }

        return $this->rule_type === self::NTH_WEEKDAY
            ? $this->nthWeekdayOf($year)
            : $this->fixedDateOf($year);
    }

    private function fixedDateOf(int $year): ?CarbonImmutable
    {
        // El 29 de febrero no existe fuera de los bisiestos.
        if (! checkdate($this->month, $this->day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $this->month, $this->day)->startOfDay();
    }

    private function nthWeekdayOf(int $year): CarbonImmutable
    {
        $date = CarbonImmutable::create($year, $this->month, 1)->startOfDay();

        while ($date->dayOfWeek !== $this->weekday) {
            $date = $date->addDay();
        }

        return $date->addWeeks($this->week_of_month - 1);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
