<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checada cruda. Es evidencia: no se edita ni se borra desde la aplicación.
 *
 * Una sola tabla para todas las fuentes (checador, app, web, captura manual
 * de RH) para que el cálculo de asistencia lea de un solo lugar.
 */
class AttendancePunch extends Model
{
    public const TYPE_IN = 0;

    public const TYPE_OUT = 1;

    public const TYPE_BREAK_OUT = 2;

    public const TYPE_BREAK_IN = 3;

    public const TYPE_OVERTIME_IN = 4;

    public const TYPE_OVERTIME_OUT = 5;

    /** Códigos de estado del protocolo ZK. */
    public const TYPES = [
        self::TYPE_IN => 'Entrada',
        self::TYPE_OUT => 'Salida',
        self::TYPE_BREAK_OUT => 'Salida a comida',
        self::TYPE_BREAK_IN => 'Regreso de comida',
        self::TYPE_OVERTIME_IN => 'Entrada horas extra',
        self::TYPE_OVERTIME_OUT => 'Salida horas extra',
    ];

    /** Modos de verificación del protocolo ZK. */
    public const VERIFY_MODES = [
        0 => 'Contraseña',
        1 => 'Huella',
        2 => 'Tarjeta',
        15 => 'Rostro',
    ];

    public const SOURCES = ['device', 'app', 'web', 'manual'];

    protected $fillable = [
        'employee_id', 'device_id', 'pin', 'punched_at', 'punch_type',
        'verify_mode', 'work_code', 'source', 'created_by', 'notes', 'raw_line',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'punch_type' => 'integer',
            'verify_mode' => 'integer',
            'work_code' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(TimeClockDevice::class, 'device_id');
    }

    public function timeClockUser(): BelongsTo
    {
        return $this->belongsTo(TimeClockUser::class, 'pin', 'pin');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /** Rango de fechas inclusivo, recibiendo solo el día. */
    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('punched_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }

    public function scopeOnDate(Builder $query, ?string $date = null): Builder
    {
        $date ??= now(config('time_clock.timezone'))->toDateString();

        return $query->betweenDates($date, $date);
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::TYPES[$this->punch_type] ?? "Desconocido ({$this->punch_type})");
    }

    protected function verifyModeLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->verify_mode === null
            ? null
            : (self::VERIFY_MODES[$this->verify_mode] ?? "Otro ({$this->verify_mode})"));
    }
}
