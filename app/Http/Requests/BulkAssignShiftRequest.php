<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAssignShiftRequest extends FormRequest
{
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
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_ids' => 'empleados',
            'employee_ids.*' => 'empleado',
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
            'notes' => 'notas',
        ];
    }
}
