<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Location;
use App\Models\TimeClockDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    private const SN = 'NYU7253800622';

    protected function setUp(): void
    {
        parent::setUp();

        config(['time_clock.log' => false]);
    }

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

    public function test_lists_locations_with_search_and_active_filter(): void
    {
        $this->actingAsUserWith('sucursales.ver');

        Location::create(['name' => 'Matriz', 'code' => 'MAT']);
        Location::create(['name' => 'Boulevard Durango', 'code' => 'BLV']);
        Location::create(['name' => 'Factor', 'code' => 'FAC', 'is_active' => false]);

        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/locations?only_active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/locations?search=durango')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BLV');
    }

    public function test_creates_a_location(): void
    {
        $this->actingAsUserWith('sucursales.crear');

        $this->postJson('/api/locations', [
            'name' => 'Factor',
            'code' => 'FAC',
            'address' => 'Av. Factor 123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Factor')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('locations', ['name' => 'Factor', 'code' => 'FAC']);
    }

    public function test_rejects_a_duplicated_name(): void
    {
        $this->actingAsUserWith('sucursales.crear');

        Location::create(['name' => 'Matriz', 'code' => 'MAT']);

        $this->postJson('/api/locations', ['name' => 'Matriz'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_updates_a_location(): void
    {
        $this->actingAsUserWith('sucursales.editar');

        $location = Location::create(['name' => 'Matriz', 'code' => 'MAT']);

        $this->putJson("/api/locations/{$location->id}", ['phone' => '618 123 4567'])
            ->assertOk()
            ->assertJsonPath('data.phone', '618 123 4567')
            ->assertJsonPath('data.name', 'Matriz');
    }

    public function test_refuses_to_delete_a_location_with_devices(): void
    {
        $this->actingAsUserWith('sucursales.eliminar');

        $location = Location::create(['name' => 'Matriz', 'code' => 'MAT']);
        $device = TimeClockDevice::create([
            'serial_number' => self::SN,
            'location_id' => $location->id,
        ]);

        $this->deleteJson("/api/locations/{$location->id}")->assertUnprocessable();

        $device->delete();

        $this->deleteJson("/api/locations/{$location->id}")->assertNoContent();
        $this->assertSoftDeleted('locations', ['id' => $location->id]);
    }

    public function test_requires_permission(): void
    {
        $this->actingAsUserWith('empleados.ver');

        $this->getJson('/api/locations')->assertForbidden();
        $this->postJson('/api/locations', ['name' => 'Matriz'])->assertForbidden();
    }

    public function test_punches_keep_the_location_where_they_were_marked(): void
    {
        $matriz = Location::create(['name' => 'Matriz', 'code' => 'MAT']);
        $factor = Location::create(['name' => 'Factor', 'code' => 'FAC']);

        $employee = Employee::factory()->create(['rfc' => 'VALS900101AAA']);
        $device = TimeClockDevice::create([
            'serial_number' => self::SN,
            'location_id' => $matriz->id,
        ]);

        $this->postAttlog("VALS900101AAA\t2026-09-14 08:00:00\t0\t15\t0\n");

        $this->assertDatabaseHas('attendance_punches', [
            'employee_id' => $employee->id,
            'location_id' => $matriz->id,
        ]);

        $device->update(['location_id' => $factor->id]);

        $this->postAttlog("VALS900101AAA\t2026-09-14 18:00:00\t1\t15\t0\n", '200');

        $this->assertSame($matriz->id, AttendancePunch::orderBy('punched_at')->first()->location_id);
        $this->assertSame($factor->id, AttendancePunch::orderByDesc('punched_at')->first()->location_id);
    }

    /** El mismo empleado checa en dos sucursales: el filtro responde por lugar. */
    public function test_filters_punches_by_location(): void
    {
        $this->actingAsUserWith('checador.ver');

        $matriz = Location::create(['name' => 'Matriz', 'code' => 'MAT']);
        $factor = Location::create(['name' => 'Factor', 'code' => 'FAC']);
        $employee = Employee::factory()->create();

        AttendancePunch::create([
            'employee_id' => $employee->id, 'location_id' => $matriz->id, 'pin' => '1001',
            'punched_at' => '2026-09-14 08:00:00', 'punch_type' => AttendancePunch::TYPE_IN,
        ]);
        AttendancePunch::create([
            'employee_id' => $employee->id, 'location_id' => $factor->id, 'pin' => '1001',
            'punched_at' => '2026-09-15 08:00:00', 'punch_type' => AttendancePunch::TYPE_IN,
        ]);

        $this->getJson('/api/time-clock/punches')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/time-clock/punches?location_id={$factor->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location', 'Factor');
    }

    public function test_assigns_a_location_to_a_device(): void
    {
        $this->actingAsUserWith('checador.ver', 'checador.administrar');

        $location = Location::create(['name' => 'Boulevard Durango', 'code' => 'BLV']);
        $device = TimeClockDevice::create(['serial_number' => self::SN, 'name' => 'Mostrador']);

        $this->putJson("/api/time-clock/devices/{$device->id}", [
            'location_id' => $location->id,
            'location' => 'Recepción',
        ])
            ->assertOk()
            ->assertJsonPath('data.location_name', 'Boulevard Durango')
            ->assertJsonPath('data.location', 'Recepción');

        $this->assertDatabaseHas('time_clock_devices', [
            'id' => $device->id,
            'location_id' => $location->id,
        ]);
    }

    private function postAttlog(string $body, string $stamp = '100'): void
    {
        $this->call('POST', '/iclock/cdata?SN='.self::SN."&table=ATTLOG&Stamp={$stamp}", [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $body);
    }
}
