<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDaySchedule;
use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHolidayRequest extends FormRequest
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
        $holiday = $this->route('holiday');

        return [
            'date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('holidays', 'date')->ignore($holiday->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'observance' => ['sometimes', 'required', Rule::in(array_keys(Holiday::OBSERVANCES))],
            'is_mandatory' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ] + $this->hourRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->resolvedObservance() === Holiday::SPECIAL_HOURS) {
                $this->validateHours($validator, $this->hours(), required: true);
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
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if ($this->resolvedObservance() !== Holiday::SPECIAL_HOURS) {
            return $data + [
                'start_time' => null,
                'end_time' => null,
                'break_start' => null,
                'break_end' => null,
            ];
        }

        return $data + $this->hours();
    }

    private function resolvedObservance(): string
    {
        return (string) $this->input('observance', $this->route('holiday')->observance);
    }

    /**
     * Horas resultantes: las que vienen en la petición y, para las que no, las
     * que ya tiene el festivo.
     *
     * @return array<string, ?string>
     */
    private function hours(): array
    {
        $holiday = $this->route('holiday');
        $hours = [];

        foreach (['start_time', 'end_time', 'break_start', 'break_end'] as $field) {
            $current = $holiday->{$field} === null ? null : substr((string) $holiday->{$field}, 0, 5);
            $hours[$field] = $this->has($field) ? $this->input($field) : $current;
        }

        return $hours;
    }
}
