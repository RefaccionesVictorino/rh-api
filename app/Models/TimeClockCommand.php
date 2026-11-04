<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeClockCommand extends Model
{
    protected $fillable = [
        'device_id', 'command', 'type',
        'sent_at', 'confirmed_at', 'result_code', 'response',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'result_code' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(TimeClockDevice::class, 'device_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('sent_at')->orderBy('id');
    }

    /** El equipo devuelve Return >= 0 en éxito y negativo en error. */
    public function wasSuccessful(): bool
    {
        return $this->result_code !== null && $this->result_code >= 0;
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->confirmed_at !== null => $this->wasSuccessful() ? 'confirmed' : 'failed',
            $this->sent_at !== null => 'sent',
            default => 'pending',
        });
    }
}
