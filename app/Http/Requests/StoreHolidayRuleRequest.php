<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDaySchedule;
use App\Models\HolidayRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHolidayRuleRequest extends FormRequest
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
        $isNthWeekday = $this->input('rule_type') === HolidayRule::NTH_WEEKDAY;

        return [
            'name' => ['required', 'string', 'max:255'],
            'rule_type' => ['required', Rule::in(array_keys(HolidayRule::RULE_TYPES))],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => [$isNthWeekday ? 'nullable' : 'required', 'integer', 'between:1,31'],
            'weekday' => [$isNthWeekday ? 'required' : 'nullable', 'integer', 'between:0,6'],
            'week_of_month' => [$isNthWeekday ? 'required' : 'nullable', 'integer', 'between:1,5'],
            'observance' => ['required', Rule::in(array_keys(HolidayRule::OBSERVANCES))],
            'is_mandatory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'starts_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'ends_year' => ['nullable', 'integer', 'min:2000', 'max:2100', 'gte:starts_year'],
            'notes' => ['nullable', 'string', 'max:255'],
        ] + $this->hourRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('observance') === HolidayRule::SPECIAL_HOURS) {
                $this->validateHours($validator, $this->all(), required: true);
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
            'is_mandatory' => 'obligatorio por ley',
            'is_active' => 'activo',
            'starts_year' => 'año inicial',
            'ends_year' => 'año final',
            'notes' => 'notas',
        ] + $this->hourAttributes();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if ($data['rule_type'] === HolidayRule::NTH_WEEKDAY) {
            $data['day'] = null;
        } else {
            $data['weekday'] = null;
            $data['week_of_month'] = null;
        }

        if ($data['observance'] !== HolidayRule::SPECIAL_HOURS) {
            $data['start_time'] = null;
            $data['end_time'] = null;
            $data['break_start'] = null;
            $data['break_end'] = null;
        }

        return $data;
    }
}
