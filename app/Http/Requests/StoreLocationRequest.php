<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
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
                // La unicidad no está en la migración: con soft deletes, una
                // sucursal borrada bloquearía su nombre para siempre.
                Rule::unique('locations', 'name')->whereNull('deleted_at'),
            ],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('locations', 'code')->whereNull('deleted_at'),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
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
            'address' => 'dirección',
            'municipality' => 'municipio',
            'phone' => 'teléfono',
            'is_active' => 'activa',
        ];
    }
}
