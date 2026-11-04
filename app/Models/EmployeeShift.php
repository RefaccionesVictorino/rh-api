<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShift extends Model
{
    protected $fillable = [
        'employee_id',
        'shift_id',
        'starts_on',
        'ends_on',
        'assigned_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** Vigente hoy. */
    protected function isCurrent(): Attribute
    {
        return Attribute::get(fn (): bool => $this->coversDate(today()));
    }

    public function coversDate(CarbonInterface $date): bool
    {
        return $this->starts_on->lte($date)
            && ($this->ends_on === null || $this->ends_on->gte($date));
    }

    /**
     * Asignaciones vigentes en una fecha.
     */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $query
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    /**
     * Vigentes hoy o que empiezan después: las que impiden dar de baja un
     * turno.
     */
    public function scopeActiveOrFuture(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('ends_on')
            ->orWhereDate('ends_on', '>=', today()->toDateString()));
    }

    /**
     * Asignaciones cuyo periodo toca el rango [start, end]; end nulo es
     * abierto hacia el futuro.
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, ?CarbonInterface $end): Builder
    {
        return $query
            ->when($end !== null, fn (Builder $q) => $q->whereDate('starts_on', '<=', $end->toDateString()))
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $start->toDateString()));
    }
}
