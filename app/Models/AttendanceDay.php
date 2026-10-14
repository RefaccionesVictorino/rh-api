<?php

namespace App\Models;

use App\Services\AttendanceCalendarService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resumen de asistencia de un empleado en un día ya cerrado.
 *
 * Las columnas calculadas las escribe record() a partir de la evaluación del
 * calendario; la justificación y el bloqueo los decide RH y sobreviven a
 * cualquier recálculo.
 */
class AttendanceDay extends Model
{
    use SoftDeletes;

    public const INCIDENT_LATE = 'late';

    public const INCIDENT_ABSENT = 'absent';

    public const INCIDENT_MISSING_OUT = 'missing_out';

    public const INCIDENT_BREAK_EXCEEDED = 'break_exceeded';

    public const INCIDENT_TYPES = [
        self::INCIDENT_LATE => 'Retardo',
        self::INCIDENT_ABSENT => 'Falta',
        self::INCIDENT_MISSING_OUT => 'Sin checar salida',
        self::INCIDENT_BREAK_EXCEEDED => 'Comida excedida',
    ];

    public const STATUS_LABELS = [
        AttendanceCalendarService::STATUS_ON_TIME => 'Puntual',
        AttendanceCalendarService::STATUS_LATE => 'Retardo',
        AttendanceCalendarService::STATUS_ABSENT => 'Falta',
        AttendanceCalendarService::STATUS_REST => 'Descanso',
        AttendanceCalendarService::STATUS_HOLIDAY => 'Festivo',
        AttendanceCalendarService::STATUS_NO_SHIFT => 'Sin turno',
        AttendanceCalendarService::STATUS_PENDING => 'Por registrar',
        AttendanceCalendarService::STATUS_WORKED => 'Sin horario',
        AttendanceCalendarService::STATUS_VACATION => 'Vacaciones',
        AttendanceCalendarService::STATUS_SICK_LEAVE => 'Incapacidad',
        AttendanceCalendarService::STATUS_LEAVE => 'Permiso',
    ];

    /** Columnas que se toman de la evaluación del día; el resto es de RH. */
    private const EVALUATED_COLUMNS = [
        'status', 'schedule_source', 'shift_id', 'absence_id',
        'expected_start', 'expected_end', 'expected_work_minutes', 'expected_break_minutes',
        'first_in', 'last_out', 'break_out', 'break_in',
        'late_minutes', 'worked_minutes', 'break_minutes', 'break_overrun_minutes',
        'break_exceeded', 'missing_out', 'irregular_punches',
    ];

    protected $fillable = [
        'employee_id', 'date', 'status', 'schedule_source', 'shift_id', 'absence_id',
        'expected_start', 'expected_end', 'expected_work_minutes', 'expected_break_minutes',
        'first_in', 'last_out', 'break_out', 'break_in',
        'late_minutes', 'worked_minutes', 'break_minutes', 'break_overrun_minutes',
        'break_exceeded', 'missing_out', 'irregular_punches',
        'justification', 'justified_by', 'justified_at', 'locked_at', 'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'expected_work_minutes' => 'integer',
            'expected_break_minutes' => 'integer',
            'late_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'break_overrun_minutes' => 'integer',
            'break_exceeded' => 'boolean',
            'missing_out' => 'boolean',
            'irregular_punches' => 'boolean',
            'justified_at' => 'datetime',
            'locked_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function absence(): BelongsTo
    {
        return $this->belongsTo(Absence::class)->withTrashed();
    }

    public function justifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by')->withTrashed();
    }

    /**
     * Guarda la evaluación de un día. Un renglón bloqueado por nómina se
     * devuelve tal cual; uno dado de baja se reactiva, porque el único por
     * (empleado, fecha) no permite otro.
     *
     * @param  array<string, mixed>  $day  Día tal como lo arma AttendanceCalendarService.
     */
    public static function record(Employee $employee, array $day): self
    {
        $row = self::withTrashed()->firstOrNew([
            'employee_id' => $employee->id,
            'date' => $day['date'],
        ]);

        if ($row->locked_at !== null) {
            return $row;
        }

        $row->fill(self::evaluatedAttributes($day));
        $row->evaluated_at = now();
        $row->deleted_at = null;
        $row->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private static function evaluatedAttributes(array $day): array
    {
        $attributes = [
            'status' => $day['status'],
            'schedule_source' => $day['schedule_source'],
            'shift_id' => $day['shift']['id'] ?? null,
            'absence_id' => $day['absence']['id'] ?? null,
            'expected_start' => $day['expected']['start'] ?? null,
            'expected_end' => $day['expected']['end'] ?? null,
            'expected_work_minutes' => $day['expected']['work_minutes'] ?? 0,
            'expected_break_minutes' => $day['expected']['break_minutes'] ?? 0,
        ];

        foreach (self::EVALUATED_COLUMNS as $column) {
            if (! array_key_exists($column, $attributes)) {
                $attributes[$column] = $day[$column];
            }
        }

        return $attributes;
    }

    public function isJustified(): bool
    {
        return $this->justified_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Tipos de incidencia que presenta el día. Vacío en un día sin novedad.
     *
     * @return list<string>
     */
    protected function incidents(): Attribute
    {
        return Attribute::get(function (): array {
            $incidents = [];

            if ($this->status === AttendanceCalendarService::STATUS_LATE) {
                $incidents[] = self::INCIDENT_LATE;
            }

            if ($this->status === AttendanceCalendarService::STATUS_ABSENT) {
                $incidents[] = self::INCIDENT_ABSENT;
            }

            if ($this->missing_out) {
                $incidents[] = self::INCIDENT_MISSING_OUT;
            }

            if ($this->break_exceeded) {
                $incidents[] = self::INCIDENT_BREAK_EXCEEDED;
            }

            return $incidents;
        });
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::STATUS_LABELS[$this->status] ?? $this->status);
    }

    public function scopeBetweenDates(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    /** Días con alguna incidencia, o solo con la indicada. */
    public function scopeWithIncident(Builder $query, ?string $type = null): Builder
    {
        return match ($type) {
            self::INCIDENT_LATE => $query->where('status', AttendanceCalendarService::STATUS_LATE),
            self::INCIDENT_ABSENT => $query->where('status', AttendanceCalendarService::STATUS_ABSENT),
            self::INCIDENT_MISSING_OUT => $query->where('missing_out', true),
            self::INCIDENT_BREAK_EXCEEDED => $query->where('break_exceeded', true),
            default => $query->where(fn (Builder $q) => $q
                ->whereIn('status', [AttendanceCalendarService::STATUS_LATE, AttendanceCalendarService::STATUS_ABSENT])
                ->orWhere('missing_out', true)
                ->orWhere('break_exceeded', true)),
        };
    }

    public function scopeJustified(Builder $query, bool $justified = true): Builder
    {
        return $justified
            ? $query->whereNotNull('justified_at')
            : $query->whereNull('justified_at');
    }
}
