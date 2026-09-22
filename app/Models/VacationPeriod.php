<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periodo vacacional de un año de servicio, generado en cada aniversario de
 * la fecha de ingreso.
 *
 * Los días se ganan al cumplir el año de servicio y se piden durante el año
 * siguiente: solo el periodo en curso admite solicitudes. Lo que sobra de
 * periodos anteriores es saldo pendiente, no se solicita y la empresa decide
 * si lo paga. `expires_on` (18 meses: artículo 81 más la prescripción del
 * 516) solo informa si ese pendiente sigue siendo exigible.
 */
class VacationPeriod extends Model
{
    /** Meses de gracia del artículo 81 tras el año en que se disfrutan. */
    public const MONTHS_TO_EXPIRE = 6;

    protected $fillable = [
        'employee_id',
        'year_number',
        'starts_on',
        'ends_on',
        'expires_on',
        'entitled_days',
        'adjustment_days',
        'taken_days',
        'adjustment_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
            'year_number' => 'integer',
            'entitled_days' => 'integer',
            'adjustment_days' => 'integer',
            'taken_days' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestDays(): HasMany
    {
        return $this->hasMany(VacationRequestDay::class);
    }

    protected function grantedDays(): Attribute
    {
        return Attribute::get(fn (): float => $this->entitled_days + $this->adjustment_days);
    }

    protected function remainingDays(): Attribute
    {
        return Attribute::get(fn (): float => $this->granted_days - $this->taken_days);
    }

    protected function isExpired(): Attribute
    {
        return Attribute::get(fn (): bool => $this->expires_on->isPast());
    }

    /** El año en curso del empleado: el único periodo contra el que se solicita. */
    protected function isCurrent(): Attribute
    {
        return Attribute::get(fn (): bool => $this->coversDate(today()));
    }

    /** En curso y con días: de aquí sale el saldo que se puede pedir. */
    protected function isAvailable(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_current && $this->remaining_days > 0);
    }

    /** Ya cerró su año con días sin gozar: saldo pendiente, no solicitable. */
    protected function isPending(): Attribute
    {
        return Attribute::get(fn (): bool => $this->ends_on->lt(today()) && $this->remaining_days > 0);
    }

    /** Todavía no abre: existe porque hay vacaciones programadas para cuando abra. */
    protected function isFuture(): Attribute
    {
        return Attribute::get(fn (): bool => $this->starts_on->gt(today()));
    }

    /**
     * Si un día de vacaciones se carga a este periodo: cae dentro de su año,
     * sin importar la fecha de hoy. Los periodos no se traslapan, así que
     * cada fecha tiene un solo periodo.
     */
    public function coversDate(CarbonInterface $date): bool
    {
        return $this->starts_on->lte($date) && $this->ends_on->gte($date);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', today()->toDateString())
            ->whereDate('ends_on', '>=', today()->toDateString())
            ->whereRaw('(entitled_days + adjustment_days) > taken_days');
    }

    /**
     * Los periodos más viejos se consumen primero, para que el saldo no venza
     * mientras se usa el de un año reciente.
     */
    public function scopeOldestFirst(Builder $query): Builder
    {
        return $query->orderBy('year_number');
    }

    public function recalculateTakenDays(): void
    {
        $this->update([
            'taken_days' => $this->requestDays()
                ->whereHas('request', fn (Builder $query) => $query->consuming())
                ->sum('days'),
        ]);
    }
}
