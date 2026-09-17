<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VacationRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING => 'Pendiente',
        self::APPROVED => 'Aprobada',
        self::REJECTED => 'Rechazada',
        self::CANCELLED => 'Cancelada',
    ];

    protected $fillable = [
        'employee_id',
        'starts_on',
        'ends_on',
        'requested_days',
        'status',
        'comments',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'requested_days' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(VacationRequestDay::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::STATUSES[$this->status] ?? $this->status);
    }

    /**
     * Una solicitud pendiente ya aparta el saldo: si no, dos solicitudes
     * simultáneas podrían aprobarse con los mismos días disponibles.
     */
    public function scopeConsuming(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::APPROVED]);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::PENDING, self::APPROVED], true);
    }
}
