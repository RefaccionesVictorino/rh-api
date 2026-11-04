<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDaySchedule;
use App\Models\HolidayRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHolidayRuleRequest extends FormRequest
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
        $isNthWeekday = $this->resolved()['rule_type'] === HolidayRule::NTH_WEEKDAY;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'rule_type' => ['sometimes', 'required', Rule::in(array_keys(HolidayRule::RULE_TYPES))],
            'month' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'day' => ['sometimes', $isNthWeekday ? 'nullable' : 'required', 'integer', 'between:1,31'],
            'weekday' => ['sometimes', $isNthWeekday ? 'required' : 'nullable', 'integer', 'between:0,6'],
            'week_of_month' => ['sometimes', $isNthWeekday ? 'required' : 'nullable', 'integer', 'between:1,5'],
            'observance' => ['sometimes', 'required', Rule::in(array_keys(HolidayRule::OBSERVANCES))],
            'is_mandatory' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'ends_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ] + $this->hourRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $resolved = $this->resolved();

            if ($resolved['observance'] === HolidayRule::SPECIAL_HOURS) {
                $this->validateHours($validator, $resolved, required: true);
            }

            if ($this->switchingToNthWeekday($resolved)) {
                $validator->errors()->add('weekday', 'Indica el día de la semana y la semana del mes.');
            }

            if ($resolved['ends_year'] !== null && $resolved['starts_year'] !== null
                && $resolved['ends_year'] < $resolved['starts_year']) {
                $validator->errors()->add('ends_year', 'El año final no puede ser anterior al inicial.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'rule_type' => 'tipo de regla',
            'month' => 'mes',
            'day' => 'día',
            'weekday' => 'día de la semana',
            'week_of_month' => 'semana del mes',
            'observance' => 'modalidad',
            'starts_year' => 'año inicial',
            'ends_year' => 'año final',
        ] + $this->hourAttributes();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $resolved = $this->resolved();
        $data = $this->validated() + $resolved;

        if ($resolved['rule_type'] === HolidayRule::NTH_WEEKDAY) {
            $data['day'] = null;
        } else {
            $data['weekday'] = null;
            $data['week_of_month'] = null;
        }

        if ($resolved['observance'] !== HolidayRule::SPECIAL_HOURS) {
            $data['start_time'] = null;
            $data['end_time'] = null;
            $data['break_start'] = null;
            $data['break_end'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function switchingToNthWeekday(array $resolved): bool
    {
        return $resolved['rule_type'] === HolidayRule::NTH_WEEKDAY
            && ($resolved['weekday'] === null || $resolved['week_of_month'] === null);
    }

    /**
     * Valores que tendrá la regla tras el cambio: los enviados y, para el
     * resto, los que ya tiene.
     *
     * @return array<string, mixed>
     */
    private function resolved(): array
    {
        $rule = $this->route('holiday_rule');
        $fields = [
            'rule_type', 'month', 'day', 'weekday', 'week_of_month', 'observance',
            'starts_year', 'ends_year',
        ];

        $resolved = [];

        foreach ($fields as $field) {
            $resolved[$field] = $this->has($field) ? $this->input($field) : $rule->{$field};
        }

        foreach (['start_time', 'end_time', 'break_start', 'break_end'] as $field) {
            $current = $rule->{$field} === null ? null : substr((string) $rule->{$field}, 0, 5);
            $resolved[$field] = $this->has($field) ? $this->input($field) : $current;
        }

        return $resolved;
    }
}
