<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubDepartmentRequest extends FormRequest
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
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at'),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                // La sub área padre debe estar viva y pertenecer a la misma
                // área: una rama no puede cruzarse de área a media jerarquía.
                Rule::exists('sub_departments', 'id')
                    ->where('department_id', $this->input('department_id'))
                    ->whereNull('deleted_at'),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                // Única dentro de su rama: dos ramas distintas sí pueden tener
                // cada una su "Turno Matutino".
                Rule::unique('sub_departments', 'name')
                    ->where('department_id', $this->input('department_id'))
                    ->where('parent_id', $this->input('parent_id'))
                    ->whereNull('deleted_at'),
            ],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('sub_departments', 'code')->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'La sub área padre debe pertenecer a la misma área.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'department_id' => 'área',
            'parent_id' => 'sub área padre',
            'name' => 'nombre',
            'code' => 'clave',
            'description' => 'descripción',
            'manager_id' => 'responsable',
            'is_active' => 'activo',
        ];
    }
}
