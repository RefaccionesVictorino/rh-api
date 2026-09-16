<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDaySchedule;
use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHolidayRequest extends FormRequest
{
    use ValidatesDaySchedule;

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
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')],
            'name' => ['required', 'string', 'max:255'],
            'observance' => ['required', Rule::in(array_keys(Holiday::OBSERVANCES))],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
            // Presente cuando el día es la excepción de un año sobre una regla
            // del catálogo; ausente en los días propios de la empresa.
            'holiday_rule_id' => ['nullable', 'integer', Rule::exists('holiday_rules', 'id')],
        ] + $this->hourRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('observance') === Holiday::SPECIAL_HOURS) {
                $this->validateHours($validator, $this->all(), required: true);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.unique' => 'Ya hay un día festivo registrado en esa fecha.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'fecha',
            'name' => 'nombre',
            'observance' => 'modalidad',
            'is_mandatory' => 'obligatorio por ley',
            'notes' => 'notas',
        ] + $this->hourAttributes();
    }

    /**
     * Las horas solo tienen sentido en horario especial; en las demás
     * modalidades se descartan para que no queden datos muertos.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (($data['observance'] ?? null) !== Holiday::SPECIAL_HOURS) {
            $data['start_time'] = null;
            $data['end_time'] = null;
            $data['break_start'] = null;
            $data['break_end'] = null;
        }

        return $data;
    }
}
