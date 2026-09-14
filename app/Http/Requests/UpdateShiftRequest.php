<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesShiftDays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateShiftRequest extends FormRequest
{
    use ValidatesShiftDays;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $shift = $this->route('shift');

        // Al validar el umbral contra la tolerancia hay que usar la que ya
        // tiene el turno cuando no viene en la petición.
        $tolerance = $this->input('tolerance_minutes', $shift->tolerance_minutes);

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('shifts', 'name')->ignore($shift->id)->whereNull('deleted_at'),
            ],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('shifts', 'code')->ignore($shift->id)->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tolerance_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'absence_after_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:720', 'gte:'.(int) $tolerance],
            'is_active' => ['sometimes', 'boolean'],
            // Si viene, reemplaza el horario semanal completo.
            'days' => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
        ] + $this->dayRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateDays($v));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'absence_after_minutes.gte' => 'El umbral de falta no puede ser menor que la tolerancia de retardo.',
        ] + $this->dayMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'code' => 'clave',
            'description' => 'descripción',
            'tolerance_minutes' => 'tolerancia de retardo',
            'absence_after_minutes' => 'umbral de falta',
            'is_active' => 'activo',
        ] + $this->dayAttributes();
    }
}
