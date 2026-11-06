<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cuenta del trabajador en la app móvil. Es independiente de User: no tiene
 * roles ni permisos y EnsureSystemAccess la rechaza en las rutas del panel.
 */
class WorkerAccount extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'employee_id',
        'nip',
        'nip_expires_at',
        'nip_issued_by',
        'activated_at',
        'last_login_at',
    ];

    protected $hidden = ['nip'];

    protected function casts(): array
    {
        return [
            'nip' => 'hashed',
            'nip_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function nipIssuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nip_issued_by')->withTrashed();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(WorkerDevice::class);
    }

    public function hasTemporaryNip(): bool
    {
        return $this->nip_expires_at !== null;
    }

    public function temporaryNipExpired(): bool
    {
        return $this->hasTemporaryNip() && $this->nip_expires_at->isPast();
    }

    /** Mismo criterio que el portal web: trabajador vigente y activo en el checador. */
    public function hasAppAccess(): bool
    {
        return $this->employee?->timeClockUser?->is_active === true;
    }

    /** Cierra todas las sesiones; sus teléfonos se borran en cascada. */
    public function revokeSessions(): void
    {
        $this->tokens()->delete();
    }

    /**
     * Destinos para un futuro canal de notificaciones push con Expo.
     *
     * @return list<string>
     */
    public function routeNotificationForExpo(): array
    {
        return $this->devices()->whereNotNull('expo_push_token')->pluck('expo_push_token')->all();
    }
}
