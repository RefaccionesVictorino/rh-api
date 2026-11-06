<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Usuario del checador. El PIN es global: el mismo usuario checa en cualquier
 * terminal activa, y el alta se encola hacia todas. Lo único que no viaja
 * entre equipos es la plantilla facial, que se enrola frente a cada uno.
 */
class TimeClockUser extends Model
{
    use SoftDeletes;

    public const PRIVILEGE_USER = 0;

    public const PRIVILEGE_ADMIN = 14;

    protected $fillable = [
        'pin', 'name', 'card_number', 'password',
        'privilege', 'employee_id', 'is_active', 'synced_at',
    ];

    protected $hidden = ['password'];

    /**
     * El equipo rechaza el alta si Pri viaja vacío, así que el privilegio
     * nunca debe quedar nulo aunque no se indique al crear el usuario.
     */
    protected $attributes = [
        'privilege' => self::PRIVILEGE_USER,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'privilege' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class, 'pin', 'pin');
    }

    public function isAdmin(): bool
    {
        return $this->privilege === self::PRIVILEGE_ADMIN;
    }

    /**
     * Un usuario borrado sigue ocupando su PIN y su empleado en los índices
     * únicos; se elimina en definitiva antes de que otro alta los reutilice.
     */
    public static function releaseTrashed(string $pin, ?int $employeeId = null): void
    {
        static::onlyTrashed()
            ->where(fn (Builder $query) => $query
                ->where('pin', $pin)
                ->when($employeeId !== null, fn (Builder $query) => $query->orWhere('employee_id', $employeeId)))
            ->forceDelete();
    }
}
