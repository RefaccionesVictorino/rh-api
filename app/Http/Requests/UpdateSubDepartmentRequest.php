<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubDepartmentRequest extends FormRequest
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
        $subDepartment = $this->route('sub_department');

        // Al renombrar sin mover de sitio, el área y el padre contra los que se
        // valida la unicidad son los que ya tiene el registro.
        $departmentId = $this->input('department_id', $subDepartment->department_id);
        $parentId = $this->input('parent_id', $subDepartment->parent_id);

        return [
            'department_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at'),
            ],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sub_departments', 'id')
                    ->where('department_id', $departmentId)
                    ->whereNull('deleted_at'),
                // Ni ella misma ni ninguna de sus descendientes: colgar una rama
                // de sí misma la desprendería del árbol dejando un ciclo.
                Rule::notIn($subDepartment->forbiddenParentIds()),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('sub_departments', 'name')
                    ->ignore($subDepartment->id)
                    ->where('department_id', $departmentId)
                    ->where('parent_id', $parentId)
                    ->whereNull('deleted_at'),
            ],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('sub_departments', 'code')
                    ->ignore($subDepartment->id)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'parent_id.exists' => 'La sub área padre debe pertenecer a la misma área.',
            'parent_id.not_in' => 'Una sub área no puede colgar de sí misma ni de una de sus sub áreas.',
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
