<?php

namespace App\Models;

use App\Services\CloudFrontSigner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ausencia justificada de un empleado en un rango de días naturales.
 *
 * Las incapacidades son los tres ramos del IMSS; los permisos son de la
 * empresa. Manda sobre vacaciones, excepciones, festivos y turno al calcular
 * asistencia, pero solo en los días en que se esperaba trabajar.
 */
class Absence extends Model
{
    use SoftDeletes;

    public const SICK_GENERAL = 'sick_general';

    public const SICK_WORK_RISK = 'sick_work_risk';

    public const MATERNITY = 'maternity';

    /** LFT art. 132 XXVII bis: cinco días con goce, los paga la empresa. */
    public const PATERNITY = 'paternity';

    public const PAID_LEAVE = 'paid_leave';

    public const UNPAID_LEAVE = 'unpaid_leave';

    public const TYPES = [
        self::SICK_GENERAL => 'Incapacidad por enfermedad general',
        self::SICK_WORK_RISK => 'Incapacidad por riesgo de trabajo',
        self::MATERNITY => 'Incapacidad por maternidad',
        self::PATERNITY => 'Permiso de paternidad',
        self::PAID_LEAVE => 'Permiso con goce de sueldo',
        self::UNPAID_LEAVE => 'Permiso sin goce de sueldo',
    ];

    /** Ramos del IMSS: llevan folio del certificado. */
    public const INCAPACITY_TYPES = [
        self::SICK_GENERAL,
        self::SICK_WORK_RISK,
        self::MATERNITY,
    ];

    protected $fillable = [
        'employee_id',
        'type',
        'starts_on',
        'ends_on',
        'folio',
        'issued_on',
        'document_url',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'issued_on' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::TYPES[$this->type] ?? $this->type);
    }

    protected function calendarDays(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->starts_on->diffInDays($this->ends_on) + 1);
    }

    /**
     * Firmado al leer, igual que la foto del expediente: el bucket es privado
     * y la firma caduca, así que en la columna queda la URL cruda.
     */
    protected function signedDocumentUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->document_url
            ? app(CloudFrontSigner::class)->sign($this->document_url)
            : null);
    }

    public function isIncapacity(): bool
    {
        return in_array($this->type, self::INCAPACITY_TYPES, true);
    }

    public function coversDate(CarbonInterface $date): bool
    {
        $key = $date->toDateString();

        return $this->starts_on->toDateString() <= $key && $this->ends_on->toDateString() >= $key;
    }

    /** Traslape con el rango, no contención: basta con que compartan un día. */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString());
    }

    public function scopeActiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query->overlapping($date, $date);
    }
}
