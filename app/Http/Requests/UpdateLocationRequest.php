<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
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
        $id = $this->route('location')->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('locations', 'name')->ignore($id)->whereNull('deleted_at'),
            ],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('locations', 'code')->ignore($id)->whereNull('deleted_at'),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'municipality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
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
