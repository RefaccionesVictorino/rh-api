<?php

namespace App\Http\Requests;

use App\Models\AttendancePunch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreManualPunchRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            // Hora local del checador, igual que las checadas del equipo.
            'punched_at' => ['required', 'date_format:Y-m-d H:i'],
            'punch_type' => ['required', 'integer', Rule::in(array_keys(AttendancePunch::TYPES))],
            'notes' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * La tabla no impide duplicados manuales (el índice único incluye device_id,
     * que aquí es nulo), así que se revisa a mano.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $punchedAt = $this->string('punched_at')->toString();

            if ($punchedAt > now(config('time_clock.timezone'))->format('Y-m-d H:i')) {
                $validator->errors()->add('punched_at', 'No se puede registrar una checada en el futuro.');

                return;
            }

            $exists = AttendancePunch::query()
                ->forEmployee($this->integer('employee_id'))
                ->where('punch_type', $this->integer('punch_type'))
                ->whereBetween('punched_at', ["{$punchedAt}:00", "{$punchedAt}:59"])
                ->exists();

            if ($exists) {
                $validator->errors()->add('punched_at', 'El empleado ya tiene una checada de ese tipo a esa hora.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'empleado',
            'punched_at' => 'fecha y hora',
            'punch_type' => 'tipo de checada',
            'notes' => 'motivo',
        ];
    }
}
