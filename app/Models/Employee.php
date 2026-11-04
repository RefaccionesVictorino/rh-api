<?php

namespace App\Models;

use App\Services\CloudFrontSigner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'last_name',
        'second_last_name',
        'gender',
        'rfc',
        'curp',
        'nss',
        'birth_country',
        'marital_status',
        'birthdate',
        'work_phone',
        'personal_phone',
        'personal_email',
        'address',
        'municipality',
        'postal_code',
        'photo_url',
        'hire_date',
        'sub_department_id',
        'termination_reason',
        'terminated_by',
    ];

    protected function casts(): array
    {
        return [
            // birthdate es string en la migración; se deja sin cast a propósito.
            'birthdate' => 'string',
            'hire_date' => 'date',
        ];
    }

    /**
     * Nombre completo; el apellido materno es opcional.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' ', array_filter([
            $this->name,
            $this->last_name,
            $this->second_last_name,
        ]))));
    }

    /**
     * Foto lista para mostrarse.
     *
     * En la columna se guarda la URL cruda que devolvió el microservicio de
     * carga; el bucket es privado, así que la firma se calcula al leer y nunca
     * se persiste. Una firma guardada caduca a los minutos y dejaría el
     * registro inservible.
     */
    protected function signedPhotoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->photo_url
            ? app(CloudFrontSigner::class)->sign($this->photo_url)
            : null);
    }

    public function subDepartment(): BelongsTo
    {
        return $this->belongsTo(SubDepartment::class);
    }

    public function terminatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terminated_by')->withTrashed();
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class)->orderBy('id');
    }

    /**
     * Reemplaza los contactos por los recibidos: el formulario siempre manda
     * la lista completa, así que no hace falta conciliar renglón por renglón.
     * Los anteriores quedan con baja lógica.
     *
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function syncEmergencyContacts(array $contacts): void
    {
        DB::transaction(function () use ($contacts): void {
            $this->emergencyContacts()->delete();
            $this->emergencyContacts()->createMany($contacts);
        });

        $this->unsetRelation('emergencyContacts');
    }

    public function scheduleOverrides(): HasMany
    {
        return $this->hasMany(ScheduleOverride::class);
    }

    public function vacationPeriods(): HasMany
    {
        return $this->hasMany(VacationPeriod::class);
    }

    public function vacationRequests(): HasMany
    {
        return $this->hasMany(VacationRequest::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /** Años de servicio cumplidos a una fecha. */
    public function yearsOfServiceOn(?CarbonInterface $date = null): int
    {
        if ($this->hire_date === null) {
            return 0;
        }

        return (int) $this->hire_date->diffInYears($date ?? today());
    }

    /** Cuenta de acceso al sistema; a lo más una vigente por empleado. */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** Usuario con el que checa en el reloj; a lo más uno por empleado. */
    public function timeClockUser(): HasOne
    {
        return $this->hasOne(TimeClockUser::class);
    }

    public function attendancePunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    /** Resumen diario de asistencia de los días ya cerrados. */
    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    /** Historial completo de turnos asignados. */
    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    /**
     * Asignación de turno vigente hoy. Es hasOne y no una columna en
     * employees para que el historial y el turno actual salgan de la misma
     * fuente y no puedan contradecirse.
     */
    public function currentShiftAssignment(): HasOne
    {
        return $this->hasOne(EmployeeShift::class)->activeOn(today());
    }

    /**
     * El área se llega a través de la sub área: el empleado no guarda
     * department_id, para que no pueda contradecir a su sub área.
     */
    public function department(): ?Department
    {
        return $this->subDepartment?->department;
    }

    /**
     * Empleados de un área completa, filtrando por la sub área a la que pertenecen.
     */
    public function scopeOfDepartment(Builder $query, ?int $departmentId): Builder
    {
        return $departmentId === null
            ? $query
            : $query->whereHas(
                'subDepartment',
                fn (Builder $q) => $q->where('department_id', $departmentId),
            );
    }

    public function scopeOfSubDepartment(Builder $query, ?int $subDepartmentId): Builder
    {
        return $subDepartmentId === null
            ? $query
            : $query->where('sub_department_id', $subDepartmentId);
    }

    /**
     * Búsqueda por nombre (en cualquiera de sus tres columnas), identificadores
     * fiscales, correo o municipio.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('second_last_name', 'like', $like)
                ->orWhere('rfc', 'like', $like)
                ->orWhere('curp', 'like', $like)
                ->orWhere('nss', 'like', $like)
                ->orWhere('personal_email', 'like', $like)
                ->orWhere('municipality', 'like', $like)
                // Permite buscar "Juan Pérez" aunque nombre y apellido estén en
                // columnas distintas.
                ->orWhereRaw(
                    "CONCAT_WS(' ', name, last_name, second_last_name) like ?",
                    [$like],
                );
        });
    }
}
