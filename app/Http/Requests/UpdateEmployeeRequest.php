<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public const GENDERS = ['masculino', 'femenino', 'no_binario'];

    public const MARITAL_STATUSES = [
        'Soltero(a)', 'Casado(a)', 'Divorciado(a)', 'Unión libre', 'Viudo(a)',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * RFC, CURP y NSS se comparan en mayúsculas y sin espacios: el checador y
     * los formatos oficiales los manejan así.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['rfc', 'curp'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = strtoupper(preg_replace('/\s+/', '', (string) $this->input($field)));
            }
        }

        if ($this->has('nss')) {
            $normalized['nss'] = preg_replace('/\D/', '', (string) $this->input('nss'));
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'second_last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'required', Rule::in(self::GENDERS)],
            // El RFC es el PIN con el que checa: cambiarlo dejaría al trabajador
            // sin reconocer en los equipos. Se acepta solo si viene igual.
            'rfc' => ['sometimes', Rule::in([$employee->rfc])],
            'curp' => [
                'sometimes', 'required', 'string', 'size:18',
                'regex:/^[A-Z]{4}[0-9]{6}[HMX][A-Z]{5}[A-Z0-9][0-9]$/',
                Rule::unique('employees', 'curp')->ignore($employee->id)->whereNull('deleted_at'),
            ],
            'nss' => [
                'sometimes', 'required', 'string', 'regex:/^[0-9]{11}$/',
                Rule::unique('employees', 'nss')->ignore($employee->id)->whereNull('deleted_at'),
            ],
            'birth_country' => ['sometimes', 'required', 'string', 'max:100'],
            'marital_status' => ['sometimes', 'required', Rule::in(self::MARITAL_STATUSES)],
            'birthdate' => ['sometimes', 'required', 'date_format:Y-m-d', 'before:today'],
            'work_phone' => ['sometimes', 'present', 'nullable', 'string', 'max:20'],
            'personal_phone' => ['sometimes', 'required', 'string', 'max:20'],
            'personal_email' => ['sometimes', 'required', 'email', 'max:150'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'municipality' => ['sometimes', 'required', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'required', 'string', 'regex:/^[0-9]{5}$/'],
            // La foto se cambia por su propio endpoint, que sube el archivo al
            // bucket; aquí se ignora para que un PUT del expediente no pueda
            // apuntar la columna a una URL arbitraria.
            'photo_url' => ['prohibited'],
            'hire_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'sub_department_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('sub_departments', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * La columna no admite nulos; sin teléfono de trabajo se guarda vacío.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('work_phone', $data) && $data['work_phone'] === null) {
            $data['work_phone'] = '';
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gender.in' => 'Elige un género de la lista.',
            'marital_status.in' => 'Elige un estado civil de la lista.',
            'sub_department_id.exists' => 'La sub área no existe o fue dada de baja.',
            'rfc.in' => 'El RFC no se puede cambiar: es el PIN con el que el trabajador checa.',
            'curp.regex' => 'La CURP no tiene un formato válido.',
            'curp.size' => 'La CURP debe tener 18 caracteres.',
            'nss.regex' => 'El NSS debe tener 11 dígitos.',
            'postal_code.regex' => 'El código postal debe tener 5 dígitos.',
            'photo_url.prohibited' => 'La foto se cambia desde el expediente, no con este formulario.',
            'birthdate.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'last_name' => 'apellido paterno',
            'second_last_name' => 'apellido materno',
            'gender' => 'género',
            'rfc' => 'RFC',
            'curp' => 'CURP',
            'nss' => 'NSS',
            'birth_country' => 'país de nacimiento',
            'marital_status' => 'estado civil',
            'birthdate' => 'fecha de nacimiento',
            'work_phone' => 'teléfono de trabajo',
            'personal_phone' => 'teléfono personal',
            'personal_email' => 'correo personal',
            'address' => 'domicilio',
            'municipality' => 'municipio',
            'postal_code' => 'código postal',
            'photo_url' => 'foto',
            'hire_date' => 'fecha de ingreso',
            'sub_department_id' => 'sub área',
        ];
    }
}
