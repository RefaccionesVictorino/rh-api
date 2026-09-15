<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * RFC, CURP y NSS se comparan en mayúsculas y sin espacios: el checador y
     * los formatos oficiales los manejan así. El RFC además se usa como PIN, de
     * modo que una diferencia de mayúsculas cambiaría el usuario del equipo.
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
     * Mismas reglas que la edición, pero sin `sometimes`: al crear no hay
     * registro previo del que heredar valores, así que todo campo obligatorio
     * tiene que venir en la petición.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'second_last_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', Rule::in(UpdateEmployeeRequest::GENDERS)],
            'rfc' => [
                'required', 'string',
                'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/',
                Rule::unique('employees', 'rfc')->whereNull('deleted_at'),
                // El RFC se usa como PIN del checador: si ya existe un usuario
                // con ese PIN el alta fallaría a medias, así que se detiene aquí.
                Rule::unique('time_clock_users', 'pin'),
            ],
            'curp' => [
                'required', 'string', 'size:18',
                'regex:/^[A-Z]{4}[0-9]{6}[HMX][A-Z]{5}[A-Z0-9][0-9]$/',
                Rule::unique('employees', 'curp')->whereNull('deleted_at'),
            ],
            'nss' => [
                'required', 'string', 'regex:/^[0-9]{11}$/',
                Rule::unique('employees', 'nss')->whereNull('deleted_at'),
            ],
            'birth_country' => ['required', 'string', 'max:100'],
            'marital_status' => ['required', Rule::in(UpdateEmployeeRequest::MARITAL_STATUSES)],
            'birthdate' => ['required', 'date_format:Y-m-d', 'before:today'],
            'work_phone' => ['present', 'nullable', 'string', 'max:20'],
            'personal_phone' => ['required', 'string', 'max:20'],
            'personal_email' => ['required', 'email', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            // La foto se sube por su propio endpoint una vez creado el
            // expediente: aquí solo viaja texto.
            'photo_url' => ['prohibited'],
            'hire_date' => ['required', 'date_format:Y-m-d'],
            'sub_department_id' => [
                'nullable', 'integer',
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

        if (($data['work_phone'] ?? null) === null) {
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
            'rfc.regex' => 'El RFC no tiene un formato válido.',
            'rfc.unique' => 'Ya existe un registro con ese RFC.',
            'curp.regex' => 'La CURP no tiene un formato válido.',
            'curp.size' => 'La CURP debe tener 18 caracteres.',
            'nss.regex' => 'El NSS debe tener 11 dígitos.',
            'postal_code.regex' => 'El código postal debe tener 5 dígitos.',
            'photo_url.prohibited' => 'La foto se agrega desde el expediente, una vez creado el registro.',
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
