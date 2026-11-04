<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Alta de trabajador. Lo que se verifica aquí, más allá de la validación, es
 * que el expediente y su usuario de checador nunca queden desacoplados.
 */
class EmployeeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUserWith(string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsHr(): User
    {
        return $this->actingAsUserWith('empleados.crear', 'checador.administrar');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sergio',
            'last_name' => 'Vargas',
            'second_last_name' => 'Luna',
            'gender' => 'masculino',
            'rfc' => 'VALS900101AB1',
            'curp' => 'VALS900101HJCRNR07',
            'nss' => '12345678901',
            'birth_country' => 'México',
            'marital_status' => 'Soltero(a)',
            'birthdate' => '1990-01-01',
            'work_phone' => '3312345678',
            'personal_phone' => '3387654321',
            'personal_email' => 'sergio@victorino.com',
            'address' => 'Av. Vallarta 1234',
            'municipality' => 'Guadalajara',
            'postal_code' => '44100',
            'hire_date' => '2026-09-01',
            'emergency_contacts' => [
                [
                    'name' => 'María Luna',
                    'relationship' => 'Madre',
                    'phone' => '3311112222',
                    'has_whatsapp' => true,
                    'email' => null,
                ],
            ],
        ], $overrides);
    }

    public function test_it_creates_the_employee_with_its_time_clock_user(): void
    {
        $this->actingAsHr();

        $response = $this->postJson('/api/employees', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.rfc', 'VALS900101AB1')
            ->assertJsonPath('data.full_name', 'Sergio Vargas Luna')
            ->assertJsonPath('data.time_clock_pin', 'VALS900101AB1');

        $employee = Employee::firstWhere('rfc', 'VALS900101AB1');

        $this->assertDatabaseHas('time_clock_users', [
            'pin' => 'VALS900101AB1',
            'name' => 'Sergio Vargas Luna',
            'employee_id' => $employee->id,
            'privilege' => TimeClockUser::PRIVILEGE_USER,
        ]);
    }

    public function test_it_queues_the_user_upsert_on_every_active_device(): void
    {
        $this->actingAsHr();

        $active = TimeClockDevice::create(['serial_number' => 'ACTIVE-1', 'is_active' => true]);
        $other = TimeClockDevice::create(['serial_number' => 'ACTIVE-2', 'is_active' => true]);
        $inactive = TimeClockDevice::create(['serial_number' => 'OFF-1', 'is_active' => false]);

        $this->postJson('/api/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Trabajador creado y enviado a 2 terminal(es). Falta enrolar el rostro en el equipo.');

        foreach ([$active, $other] as $device) {
            $command = $device->commands()->where('type', 'user_upsert')->sole();

            $this->assertStringContainsString('PIN=VALS900101AB1', $command->command);
            $this->assertStringContainsString('Name=Sergio Vargas Luna', $command->command);
            // El equipo descarta el alta si Pri viaja vacío.
            $this->assertStringContainsString('Pri=0', $command->command);
        }

        $this->assertSame(0, $inactive->commands()->count());
    }

    public function test_it_creates_the_employee_even_without_active_devices(): void
    {
        $this->actingAsHr();

        $this->postJson('/api/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Trabajador creado. No hay terminales activas, así que su alta en el checador quedó pendiente de enviar.',
            );

        $this->assertDatabaseHas('employees', ['rfc' => 'VALS900101AB1']);
        $this->assertDatabaseHas('time_clock_users', ['pin' => 'VALS900101AB1']);
    }

    public function test_it_normalizes_the_fiscal_identifiers(): void
    {
        $this->actingAsHr();

        $this->postJson('/api/employees', $this->payload([
            'rfc' => ' vals900101ab1 ',
            'curp' => 'vals900101hjcrnr07',
            'nss' => '123-456-789-01',
        ]))->assertCreated();

        $this->assertDatabaseHas('employees', [
            'rfc' => 'VALS900101AB1',
            'curp' => 'VALS900101HJCRNR07',
            'nss' => '12345678901',
        ]);
    }

    public function test_it_rejects_an_rfc_already_used_as_a_time_clock_pin(): void
    {
        $this->actingAsHr();

        // Usuario enrolado en el equipo que RH aún no había ligado a nadie.
        TimeClockUser::create(['pin' => 'VALS900101AB1', 'name' => 'Enrolado en pantalla']);

        $this->postJson('/api/employees', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rfc');

        $this->assertDatabaseMissing('employees', ['curp' => 'VALS900101HJCRNR07']);
    }

    public function test_it_rejects_duplicated_identifiers(): void
    {
        $this->actingAsHr();

        Employee::factory()->create(['rfc' => 'VALS900101AB1']);

        $this->postJson('/api/employees', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rfc');
    }

    public function test_it_validates_required_fields_and_formats(): void
    {
        $this->actingAsHr();

        $this->postJson('/api/employees', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name', 'last_name', 'gender', 'rfc', 'curp', 'nss',
                'birth_country', 'marital_status', 'birthdate', 'work_phone',
                'personal_phone', 'personal_email', 'address', 'municipality',
                'postal_code', 'hire_date', 'emergency_contacts',
            ]);

        $this->postJson('/api/employees', $this->payload([
            'rfc' => 'NOPE',
            'curp' => 'CORTA',
            'nss' => '123',
            'postal_code' => '441',
            'birthdate' => now()->addDay()->toDateString(),
            'gender' => 'otro',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc', 'curp', 'nss', 'postal_code', 'birthdate', 'gender']);
    }

    public function test_it_rejects_a_photo_url_in_the_payload(): void
    {
        $this->actingAsHr();

        $this->postJson('/api/employees', $this->payload(['photo_url' => 'https://evil.test/foto.jpg']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo_url');
    }

    public function test_it_accepts_an_optional_sub_department(): void
    {
        $this->actingAsHr();

        $department = Department::create(['name' => 'Almacén']);
        $subDepartment = SubDepartment::create([
            'department_id' => $department->id,
            'name' => 'Recibo',
        ]);

        $this->postJson('/api/employees', $this->payload([
            'sub_department_id' => $subDepartment->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.sub_department_id', $subDepartment->id);
    }

    public function test_it_stores_an_empty_work_phone_when_omitted(): void
    {
        $this->actingAsHr();

        $this->postJson('/api/employees', $this->payload(['work_phone' => null]))
            ->assertCreated();

        $this->assertDatabaseHas('employees', [
            'rfc' => 'VALS900101AB1',
            'work_phone' => '',
        ]);
    }

    public function test_it_requires_both_permissions(): void
    {
        $this->actingAsUserWith('empleados.crear');

        $this->postJson('/api/employees', $this->payload())->assertForbidden();

        $this->actingAsUserWith('checador.administrar');

        $this->postJson('/api/employees', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/employees', $this->payload())->assertUnauthorized();
    }

    public function test_the_rfc_cannot_be_changed_once_registered(): void
    {
        $this->actingAsUserWith('empleados.editar');

        $employee = Employee::factory()->create(['rfc' => 'VALS900101AB1']);

        $this->putJson("/api/employees/{$employee->id}", ['rfc' => 'VALS900101AB2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rfc');

        // Reenviar el mismo RFC con el resto del expediente no es un cambio.
        $this->putJson("/api/employees/{$employee->id}", ['rfc' => ' vals900101ab1 ', 'name' => 'Sergio'])
            ->assertOk();

        $this->assertSame('VALS900101AB1', $employee->fresh()->rfc);
    }

    public function test_it_stores_the_emergency_contacts_with_the_employee(): void
    {
        $this->actingAsHr();

        $this->postJson('/api/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.emergency_contacts.0.name', 'María Luna')
            ->assertJsonPath('data.emergency_contacts.0.has_whatsapp', true);

        $this->assertDatabaseHas('employee_emergency_contacts', [
            'employee_id' => Employee::firstWhere('rfc', 'VALS900101AB1')->id,
            'relationship' => 'Madre',
            'phone' => '3311112222',
            'email' => null,
        ]);
    }

    public function test_it_requires_at_least_one_valid_emergency_contact(): void
    {
        $this->actingAsHr();

        $this->postJson('/api/employees', $this->payload(['emergency_contacts' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('emergency_contacts');

        $this->postJson('/api/employees', $this->payload([
            'emergency_contacts' => [[
                'name' => '',
                'relationship' => 'Vecino',
                'phone_country_code' => '+52',
                'phone' => '',
                'email' => 'no-es-correo',
            ]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'emergency_contacts.0.name',
                'emergency_contacts.0.relationship',
                'emergency_contacts.0.phone_country_code',
                'emergency_contacts.0.phone',
                'emergency_contacts.0.email',
            ]);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_editing_replaces_the_emergency_contacts(): void
    {
        $this->actingAsUserWith('empleados.editar');

        $employee = Employee::factory()->create();
        $employee->syncEmergencyContacts([
            ['name' => 'Anterior', 'relationship' => 'Padre', 'phone' => '3300000000'],
        ]);
        $previous = $employee->emergencyContacts()->sole();

        $this->putJson("/api/employees/{$employee->id}", [
            'emergency_contacts' => [
                ['name' => 'Ana', 'relationship' => 'Cónyuge', 'phone' => '3311111111', 'has_whatsapp' => true],
                ['name' => 'Luis', 'relationship' => 'Hermano(a)', 'phone_country_code' => '1', 'phone' => '2135550100', 'email' => 'luis@correo.com'],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.emergency_contacts')
            ->assertJsonPath('data.emergency_contacts.0.phone_country_code', '52')
            ->assertJsonPath('data.emergency_contacts.1.phone_country_code', '1')
            ->assertJsonPath('data.emergency_contacts.1.email', 'luis@correo.com');

        $this->assertSoftDeleted($previous);

        $this->putJson("/api/employees/{$employee->id}", ['emergency_contacts' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('emergency_contacts');

        // Una edición que no trae la lista no toca los contactos guardados.
        $this->putJson("/api/employees/{$employee->id}", ['name' => 'Sergio'])->assertOk();

        $this->assertSame(2, $employee->emergencyContacts()->count());
    }
}
