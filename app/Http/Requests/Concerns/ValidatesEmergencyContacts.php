<?php

namespace App\Http\Requests\Concerns;

use App\Models\EmployeeEmergencyContact;
use Illuminate\Validation\Rule;

/**
 * Contactos de emergencia, compartidos entre alta y edición del expediente.
 * La lista llega completa y reemplaza a la guardada; debe traer al menos uno.
 */
trait ValidatesEmergencyContacts
{
    /**
     * @param  list<string>  $presence  `required` al crear, `sometimes` + `required` al editar.
     * @return array<string, mixed>
     */
    protected function emergencyContactRules(array $presence): array
    {
        return [
            'emergency_contacts' => [...$presence, 'array', 'min:1', 'max:'.EmployeeEmergencyContact::MAX_PER_EMPLOYEE],
            'emergency_contacts.*.name' => ['required', 'string', 'max:150'],
            'emergency_contacts.*.relationship' => ['required', Rule::in(EmployeeEmergencyContact::RELATIONSHIPS)],
            'emergency_contacts.*.phone_country_code' => ['nullable', 'string', 'regex:/^[0-9]{1,4}$/'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:20'],
            'emergency_contacts.*.has_whatsapp' => ['boolean'],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function emergencyContactMessages(): array
    {
        return [
            'emergency_contacts.required' => 'Agrega al menos un contacto de emergencia.',
            'emergency_contacts.min' => 'Agrega al menos un contacto de emergencia.',
            'emergency_contacts.max' => 'Se pueden registrar hasta '.EmployeeEmergencyContact::MAX_PER_EMPLOYEE.' contactos de emergencia.',
            'emergency_contacts.*.relationship.in' => 'Elige un parentesco de la lista.',
            'emergency_contacts.*.phone_country_code.regex' => 'La lada debe tener de 1 a 4 dígitos, sin "+".',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function emergencyContactAttributes(): array
    {
        return [
            'emergency_contacts' => 'contactos de emergencia',
            'emergency_contacts.*.name' => 'nombre del contacto',
            'emergency_contacts.*.relationship' => 'parentesco',
            'emergency_contacts.*.phone_country_code' => 'lada',
            'emergency_contacts.*.phone' => 'teléfono del contacto',
            'emergency_contacts.*.has_whatsapp' => 'WhatsApp',
            'emergency_contacts.*.email' => 'correo del contacto',
        ];
    }

    /**
     * Contactos listos para guardarse, o null si la petición no los trae.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function emergencyContacts(): ?array
    {
        if (! $this->has('emergency_contacts')) {
            return null;
        }

        return array_map(fn (array $contact): array => [
            'name' => $contact['name'],
            'relationship' => $contact['relationship'],
            'phone_country_code' => $contact['phone_country_code'] ?? '52',
            'phone' => $contact['phone'],
            'has_whatsapp' => (bool) ($contact['has_whatsapp'] ?? false),
            'email' => $contact['email'] ?? null,
        ], $this->validated('emergency_contacts'));
    }
}
