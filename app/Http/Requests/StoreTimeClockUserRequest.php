<?php

namespace App\Http\Requests;

use App\Models\TimeClockUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimeClockUserRequest extends FormRequest
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
            'pin' => ['required', 'string', 'max:20', 'unique:time_clock_users,pin'],
            'name' => ['required', 'string', 'max:60'],
            'card_number' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'max:8'],
            'privilege' => ['nullable', 'integer', Rule::in([TimeClockUser::PRIVILEGE_USER, TimeClockUser::PRIVILEGE_ADMIN])],
            'employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
                // Un empleado, un usuario de checador.
                Rule::unique('time_clock_users', 'employee_id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.unique' => 'Ese empleado ya tiene un usuario de checador.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pin' => 'PIN',
            'name' => 'nombre',
            'card_number' => 'tarjeta',
            'password' => 'contraseña',
            'privilege' => 'privilegio',
            'employee_id' => 'empleado',
        ];
    }
}
