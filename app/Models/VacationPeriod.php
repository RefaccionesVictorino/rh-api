<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periodo vacacional de un año de servicio, generado en cada aniversario de
 * la fecha de ingreso.
 */
class VacationPeriod extends Model
{
    /** El artículo 81 da seis meses tras el año de servicio para disfrutarlas. */
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

    /** Ya empezó y todavía no vence: de aquí sale el saldo que se puede pedir. */
    protected function isAvailable(): Attribute
    {
        return Attribute::get(
            fn (): bool => ! $this->is_expired
                && $this->starts_on->lte(today())
                && $this->remaining_days > 0
        );
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', today()->toDateString())
            ->whereDate('expires_on', '>=', today()->toDateString())
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
