<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Services\OrgChartScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * Fuera de alcance va como error de validación por renglón, igual que los
     * traslapes de assignMany: así se señala a quién quitar de la lista.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $ids = array_map('intval', $this->input('employee_ids'));
                $employees = Employee::whereIn('id', $ids)->get();

                $outOfScope = app(OrgChartScope::class)
                    ->outOfScope($this->user(), $employees)
                    ->keyBy('id');

                foreach ($ids as $index => $id) {
                    if ($outOfScope->has($id)) {
                        $validator->errors()->add(
                            "employee_ids.{$index}",
                            "{$outOfScope[$id]->full_name} no depende de ti en el organigrama.",
                        );
                    }
                }
            },
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
