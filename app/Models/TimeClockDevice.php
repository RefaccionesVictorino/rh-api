<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimeClockDevice extends Model
{
    protected $fillable = [
        'serial_number', 'location_id', 'name', 'location', 'model', 'firmware',
        'ip_address', 'is_active', 'att_log_stamp', 'op_log_stamp', 'last_seen_at',
    ];

    /**
     * Un Stamp vacío hace que ciertos firmwares no reenvíen el historial
     * pendiente, así que nunca debe viajar nulo hacia el equipo.
     */
    protected $attributes = [
        'att_log_stamp' => '0',
        'op_log_stamp' => '0',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Sucursal donde está instalado. La columna `location` es distinta: texto
     * libre para ubicar el equipo dentro de la sucursal ("recepción", "almacén").
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class, 'device_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(TimeClockCommand::class, 'device_id');
    }

    /**
     * En línea si dio señales de vida dentro de tres intervalos de sondeo.
     */
    public function isOnline(): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        $margin = (int) config('time_clock.delay', 30) * 3;

        return $this->last_seen_at->gt(now()->subSeconds($margin));
    }

    /**
     * Encola un comando para que el equipo lo recoja en su siguiente sondeo.
     */
    public function queueCommand(string $command, ?string $type = null): TimeClockCommand
    {
        return $this->commands()->create([
            'command' => $command,
            'type' => $type,
        ]);
    }
}
