<?php

namespace App\Http\Requests;

use App\Models\Absence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAbsenceRequest extends FormRequest
{
    /** Columnas por las que se permite ordenar. */
    public const SORTABLE = [
        'employee',
        'type',
        'starts_on',
        'ends_on',
        'created_at',
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
            'type' => ['nullable', Rule::in(array_keys(Absence::TYPES))],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sub_department_id' => ['nullable', 'integer', 'exists:sub_departments,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Solo las que cubren el día de hoy.
            'active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort_by' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    /** La más reciente en fechas primero: es lo que RH quiere ver hoy. */
    public function sortBy(): string
    {
        return (string) ($this->validated('sort_by') ?? 'starts_on');
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
        ];
    }
}
