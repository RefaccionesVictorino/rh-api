<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserAccess;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', 'string', Rule::in(User::ASSIGNABLE_ROLES)],
            'employee_id' => $this->employeeRules(),
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateEmployeeLink(
                $validator,
                $this->input('role'),
                $this->input('employee_id'),
            ),
        ];
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
