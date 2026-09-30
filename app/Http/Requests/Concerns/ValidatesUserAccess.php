<?php

namespace App\Http\Requests\Concerns;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas del vínculo entre usuario y trabajador, compartidas entre alta y
 * edición de usuarios.
 */
trait ValidatesUserAccess
{
    /**
     * @return array<string, mixed>
     */
    protected function employeeRules(?int $ignoreUserId = null): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('employees', 'id')->whereNull('deleted_at'),
            Rule::unique('users', 'employee_id')->whereNull('deleted_at')->ignore($ignoreUserId),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function userMessages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'role.in' => 'Elige un rol de la lista.',
            'employee_id.exists' => 'El trabajador no existe o está dado de baja.',
            'employee_id.unique' => 'Ese trabajador ya tiene un usuario.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function userAttributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo',
            'password' => 'contraseña',
            'role' => 'rol',
            'employee_id' => 'trabajador',
        ];
    }

    /**
     * Revisa el rol y el trabajador con los que quedaría el usuario, venga cada
     * uno de la petición o de lo ya guardado.
     */
    protected function validateEmployeeLink(Validator $validator, ?string $role, mixed $employeeId): void
    {
        if ($validator->errors()->hasAny(['role', 'employee_id'])) {
            return;
        }

        if ($employeeId === null) {
            if ($role !== User::ROLE_ADMIN) {
                $validator->errors()->add(
                    'employee_id',
                    'Solo un administrador puede tener acceso sin estar vinculado a un trabajador.',
                );
            }

            return;
        }

        if (Employee::whereKey($employeeId)->value('sub_department_id') === null) {
            $validator->errors()->add(
                'employee_id',
                'El trabajador no tiene área asignada. Ubícalo en una sub área antes de darle acceso.',
            );
        }
    }
}
