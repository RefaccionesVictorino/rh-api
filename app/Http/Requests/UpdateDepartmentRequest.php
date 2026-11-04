<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
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
        $id = $this->route('department')->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('departments', 'name')->ignore($id)->whereNull('deleted_at'),
            ],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('departments', 'code')->ignore($id)->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'manager_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.regex' => 'El color debe venir en formato hexadecimal, por ejemplo #1f7a3d.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'code' => 'clave',
            'description' => 'descripción',
            'color' => 'color',
            'manager_id' => 'responsable',
            'is_active' => 'activo',
        ];
    }

    /**
     * El color llega del input type=color del navegador, que en algunos casos lo
     * manda en mayúsculas: se normaliza para que dos capturas del mismo tono no
     * queden como cadenas distintas.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('color'))) {
            $this->merge(['color' => strtolower(trim($this->input('color')))]);
        }
    }
}
