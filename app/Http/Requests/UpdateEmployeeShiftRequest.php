<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeShiftRequest extends FormRequest
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
        $assignment = $this->route('shift_assignment');

        // Si solo cambia la fecha de fin, se compara contra el inicio que ya
        // tiene la asignación.
        $startsOn = $this->input('starts_on', $assignment->starts_on->toDateString());

        return [
            'shift_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('shifts', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$startsOn],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shift_id.exists' => 'El turno no existe o está inactivo.',
            'ends_on.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'shift_id' => 'turno',
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
            'notes' => 'notas',
        ];
    }
}
