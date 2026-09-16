<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDaySchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreScheduleOverrideRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'is_rest_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ] + $this->hourRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->boolean('is_rest_day')) {
                return;
            }

            $this->validateHours($validator, $this->all(), required: true);
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'fecha',
            'is_rest_day' => 'descanso',
            'reason' => 'motivo',
        ] + $this->hourAttributes();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if ($this->boolean('is_rest_day')) {
            return $data + [
                'start_time' => null,
                'end_time' => null,
                'break_start' => null,
                'break_end' => null,
            ];
        }

        return $data;
    }
}
