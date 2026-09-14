<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                // La unicidad no está en la migración: con soft deletes, un área
                // borrada seguiría bloqueando su nombre para siempre.
                Rule::unique('departments', 'name')->whereNull('deleted_at'),
            ],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('departments', 'code')->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'manager_id' => [
                'nullable',
                'integer',
                // La FK aceptaría un empleado dado de baja: para MySQL esa fila
                // sigue existiendo.
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
