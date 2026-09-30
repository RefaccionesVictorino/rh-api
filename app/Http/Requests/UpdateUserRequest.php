<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserAccess;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    use ValidatesUserAccess;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($user->id),
            ],
            // Vacía = conservar la actual.
            'password' => ['sometimes', 'nullable', 'string', Password::min(8)],
            'role' => ['sometimes', 'required', 'string', Rule::in(User::ASSIGNABLE_ROLES)],
            'employee_id' => ['sometimes', ...$this->employeeRules($user->id)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->route('user');

                // Quitarse a sí mismo el rol de administrador dejaría el módulo
                // sin quien lo administre si es el único.
                if ($this->has('role') && $user->is($this->user()) && $this->input('role') !== $this->currentRole()) {
                    $validator->errors()->add('role', 'No puedes cambiar tu propio rol.');

                    return;
                }

                $this->validateEmployeeLink(
                    $validator,
                    $this->input('role', $this->currentRole()),
                    $this->has('employee_id') ? $this->input('employee_id') : $user->employee_id,
                );
            },
        ];
    }

    private function currentRole(): ?string
    {
        return $this->route('user')->getRoleNames()->first();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->userMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->userAttributes();
    }
}
