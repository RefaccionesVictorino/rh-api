<?php

namespace App\Models;

use App\Models\Concerns\HasObservance;
use App\Models\Concerns\HasWorkWindow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Día festivo con fecha propia: los de la empresa (inventario, aniversario) y
 * las excepciones de un año concreto sobre una regla del catálogo.
 */
class Holiday extends Model
{
    use HasObservance, HasWorkWindow;

    protected $fillable = [
        'holiday_rule_id',
        'date',
        'name',
        'observance',
        'start_time',
        'end_time',
        'break_start',
        'break_end',
        'is_mandatory',
        'notes',
    ];

    protected function casts(): array
    {
        // Sin el formato, Carbon escribe "Y-m-d 00:00:00" y la regla unique de
        // la fecha nunca encuentra el duplicado.
        return [
            'date' => 'date:Y-m-d',
            'is_mandatory' => 'boolean',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(HolidayRule::class, 'holiday_rule_id');
    }

    public function scopeBetweenDates(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    public function scopeInYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('date', $year);
    }
}
