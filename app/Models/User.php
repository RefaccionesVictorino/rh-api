<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_HR = 'rh';

    public const ROLE_AREA_MANAGER = 'jefe_area';

    /** Roles que se otorgan desde el módulo de usuarios. */
    public const ASSIGNABLE_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_HR,
        self::ROLE_AREA_MANAGER,
    ];

    /** Operan sobre toda la plantilla aunque estén vinculados a un trabajador. */
    public const UNSCOPED_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_HR,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Sustituye el correo de Laravel, que apunta a una ruta web inexistente en
     * el API, por uno que lleva al formulario del frontend.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Sobrescribe la de HasDatabaseNotifications para usar el modelo con baja
     * lógica; el canal `database` también guarda a través de esta relación.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    /**
     * Incluye trabajadores dados de baja: el usuario debe seguir mostrando a
     * quién pertenece aunque por eso mismo ya no tenga acceso.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * El administrador siempre entra, y es el único que puede no tener
     * trabajador (lo validan StoreUserRequest y UpdateUserRequest). Quien está
     * vinculado entra mientras su trabajador siga vigente y tenga área.
     */
    public function hasSystemAccess(): bool
    {
        if ($this->employee_id === null || $this->hasRole(self::ROLE_ADMIN)) {
            return true;
        }

        $employee = $this->employee;

        return $employee !== null
            && ! $employee->trashed()
            && $employee->sub_department_id !== null;
    }

    /**
     * Búsqueda por nombre o correo del usuario, o por el nombre del trabajador
     * vinculado.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $query) use ($like, $term): void {
            $query
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('employee', fn (Builder $q) => $q->search($term));
        });
    }
}
