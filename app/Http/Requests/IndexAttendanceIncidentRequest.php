<?php

namespace App\Http\Requests;

use App\Models\AttendanceDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAttendanceIncidentRequest extends FormRequest
{
    /** Columnas por las que se permite ordenar. */
    public const SORTABLE = [
        'date',
        'employee',
        'late_minutes',
        'break_overrun_minutes',
    ];

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 200;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(array_keys(AttendanceDay::INCIDENT_TYPES))],
            // Omitido = todas; true solo justificadas; false solo pendientes.
            'justified' => ['nullable', 'boolean'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sub_department_id' => ['nullable', 'integer', 'exists:sub_departments,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort_by' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Sin fechas: del primer día del mes a ayer, que es el último día cerrado.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $yesterday = CarbonImmutable::now(config('time_clock.timezone'))->startOfDay()->subDay();

        $from = $this->filled('from')
            ? CarbonImmutable::parse($this->validated('from'))->startOfDay()
            : $yesterday->startOfMonth();

        $to = $this->filled('to')
            ? CarbonImmutable::parse($this->validated('to'))->startOfDay()
            : $yesterday;

        return [$from, $to];
    }

    public function justifiedFilter(): ?bool
    {
        return $this->has('justified') && $this->input('justified') !== null && $this->input('justified') !== ''
            ? $this->boolean('justified')
            : null;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function sortBy(): string
    {
        return (string) ($this->validated('sort_by') ?? 'date');
    }

    public function sortDir(): string
    {
        return (string) ($this->validated('sort_dir') ?? 'desc');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'fecha inicial',
            'to' => 'fecha final',
            'type' => 'tipo de incidencia',
        ];
    }
}
