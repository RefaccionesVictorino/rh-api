<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\TimeClockCommand;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TimeClockTest extends TestCase
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

    /** Lote ATTLOG tal como lo manda el equipo: PIN, fecha, estado, verificación, workcode. */
    private function attlog(array $rows): string
    {
        return implode("\n", array_map(fn (array $row) => implode("\t", $row), $rows))."\n";
    }

    private function postAttlog(string $body, string $stamp = '100'): TestResponse
    {
        return $this->call('POST', '/iclock/cdata?SN='.self::SN."&table=ATTLOG&Stamp={$stamp}", [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $body);
    }

    public function test_handshake_registers_the_device_and_returns_its_options(): void
    {
        $response = $this->get('/iclock/cdata?SN='.self::SN.'&options=all&pushver=2.4.1');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $this->assertStringStartsWith('GET OPTION FROM: '.self::SN, $response->getContent());
        $this->assertStringContainsString('ATTLOGStamp=0', $response->getContent());
        $this->assertStringContainsString('Realtime=1', $response->getContent());

        $this->assertDatabaseHas('time_clock_devices', [
            'serial_number' => self::SN,
            'is_active' => true,
        ]);
        $this->assertNotNull(TimeClockDevice::first()->last_seen_at);
    }

    public function test_unknown_serial_is_ignored_when_auto_register_is_off(): void
    {
        config(['time_clock.auto_register' => false]);

        $this->get('/iclock/cdata?SN=DESCONOCIDO&options=all')
            ->assertOk()
            ->assertSee('OK');

        $this->assertDatabaseCount('time_clock_devices', 0);
    }

    public function test_stores_punches_linked_to_the_employee_and_ignores_resent_batches(): void
    {
        $employee = Employee::factory()->create();
        TimeClockUser::create(['pin' => '1001', 'name' => 'Ana', 'employee_id' => $employee->id]);

        $batch = $this->attlog([
            ['1001', '2026-09-14 08:58:12', '0', '15', '0', '0'],
            ['1001', '2026-09-14 18:03:40', '1', '15', '0', '0'],
            // PIN que RH todavía no ligó a un empleado: se guarda sin employee_id.
            ['2002', '2026-09-14 09:10:00', '0', '2', '0', '0'],
        ]);

        $this->postAttlog($batch)->assertOk()->assertSee('OK: 3');

        // El equipo no recibió el acuse y reenvía el mismo lote.
        $this->postAttlog($batch, '101')->assertOk()->assertSee('OK: 0');

        $this->assertDatabaseCount('attendance_punches', 3);

        $entry = AttendancePunch::where('pin', '1001')->where('punch_type', AttendancePunch::TYPE_IN)->first();
        $this->assertSame($employee->id, $entry->employee_id);
        $this->assertSame('2026-09-14 08:58:12', $entry->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame('Rostro', $entry->verify_mode_label);
        $this->assertSame('device', $entry->source);

        $this->assertNull(AttendancePunch::where('pin', '2002')->first()->employee_id);

        // La marca de agua avanza al último lote acusado.
        $this->assertSame('101', TimeClockDevice::first()->att_log_stamp);
    }

    public function test_delivers_pending_commands_and_records_their_confirmation(): void
    {
        $device = TimeClockDevice::create(['serial_number' => self::SN]);
        $device->queueCommand('REBOOT', 'reboot');

        $this->get('/iclock/getrequest?SN='.self::SN)
            ->assertOk()
            ->assertSee('C:1:REBOOT');

        $this->assertSame('sent', TimeClockCommand::first()->status);

        // Ya entregado: el siguiente sondeo no lo repite.
        $this->get('/iclock/getrequest?SN='.self::SN)->assertOk()->assertSee('OK');

        $this->call('POST', '/iclock/devicecmd?SN='.self::SN, [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], "ID=1&Return=0&CMD=REBOOT\n")->assertOk();

        $command = TimeClockCommand::first();
        $this->assertSame('confirmed', $command->status);
        $this->assertSame(0, $command->result_code);
    }

    public function test_users_enrolled_on_the_device_are_synced_into_the_catalog(): void
    {
        TimeClockDevice::create(['serial_number' => self::SN]);

        $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=OPERLOG&Stamp=7', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], "USER PIN=3003\tName=Luis Pérez\tPri=0\tCard=778899\n")->assertOk()->assertSee('OK: 1');

        $this->assertDatabaseHas('time_clock_users', [
            'pin' => '3003',
            'name' => 'Luis Pérez',
            'card_number' => '778899',
        ]);
        $this->assertSame('7', TimeClockDevice::first()->op_log_stamp);
    }

    public function test_device_users_whose_pin_is_an_rfc_are_linked_to_the_employee(): void
    {
        TimeClockDevice::create(['serial_number' => self::SN]);
        $employee = Employee::factory()->create(['rfc' => 'GARA061007MD5']);

        // Tal como lo manda el equipo real: campos vacíos como la cadena "null".
        $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=OPERLOG&OpStamp=9999', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], "USER PIN=GARA061007MD5\tName=ANDREA LIZZETH GARVALENA\tPri=0\tPasswd=null\tCard=null\tGrp=1\n")
            ->assertOk()->assertSee('OK: 1');

        $user = TimeClockUser::where('pin', 'GARA061007MD5')->first();
        $this->assertSame($employee->id, $user->employee_id);
        $this->assertNull($user->card_number);

        // Una segunda sincronización no rompe la liga.
        $user->update(['employee_id' => $employee->id]);
        $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=OPERLOG', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], "USER PIN=GARA061007MD5\tName=ANDREA LIZZETH G\tPri=0\n")->assertOk();

        $this->assertSame($employee->id, $user->fresh()->employee_id);
        $this->assertSame('ANDREA LIZZETH G', $user->fresh()->name);
    }

    public function test_punches_fall_back_to_the_employee_rfc_when_there_is_no_device_user(): void
    {
        $employee = Employee::factory()->create(['rfc' => 'EOGA980330FV0']);

        $this->postAttlog($this->attlog([
            ['EOGA980330FV0', '2026-09-14 08:00:00', '0', '15', '0', '0'],
        ]))->assertOk()->assertSee('OK: 1');

        $this->assertSame($employee->id, AttendancePunch::first()->employee_id);
    }

    public function test_creating_a_user_from_the_api_queues_it_to_every_active_device(): void
    {
        $this->actingAsUserWith('checador.administrar');

        $employee = Employee::factory()->create();
        TimeClockDevice::create(['serial_number' => 'A1']);
        TimeClockDevice::create(['serial_number' => 'B2']);
        TimeClockDevice::create(['serial_number' => 'OFF', 'is_active' => false]);

        $this->postJson('/api/time-clock/users', [
            'pin' => '1001',
            'name' => 'Ana López',
            'employee_id' => $employee->id,
        ])->assertStatus(202)
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.employee_name', $employee->full_name);

        // Dos equipos activos, un comando de alta en cada uno.
        $this->assertSame(2, TimeClockCommand::where('type', 'user_upsert')->count());
        $this->assertStringContainsString("PIN=1001\tName=Ana López", TimeClockCommand::first()->command);

        // El mismo empleado no puede tener dos usuarios de checador.
        $this->postJson('/api/time-clock/users', [
            'pin' => '1002', 'name' => 'Ana otra vez', 'employee_id' => $employee->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);

        $userId = TimeClockUser::first()->id;

        $this->deleteJson("/api/time-clock/users/{$userId}")->assertStatus(202);

        $this->assertDatabaseCount('time_clock_users', 0);
        $this->assertSame(2, TimeClockCommand::where('type', 'user_delete')->count());
    }

    public function test_executes_named_commands_only(): void
    {
        $this->actingAsUserWith('checador.administrar');

        $device = TimeClockDevice::create(['serial_number' => self::SN]);

        $this->postJson("/api/time-clock/devices/{$device->id}/execute", ['action' => 'unlock_door', 'seconds' => 3])
            ->assertStatus(202)
            ->assertJsonPath('data.command', 'AC_UNLOCK=3')
            ->assertJsonPath('data.status', 'pending');

        $this->postJson("/api/time-clock/devices/{$device->id}/execute", ['action' => 'message'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['text']);

        $this->postJson("/api/time-clock/devices/{$device->id}/execute", ['action' => 'DATA DELETE USERINFO'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);
    }

    public function test_lists_punches_filtered_by_employee_and_requires_permission(): void
    {
        $this->actingAsUserWith('checador.ver');

        $employee = Employee::factory()->create();
        $device = TimeClockDevice::create(['serial_number' => self::SN, 'name' => 'Mostrador']);

        AttendancePunch::create([
            'employee_id' => $employee->id, 'device_id' => $device->id, 'pin' => '1001',
            'punched_at' => '2026-09-14 09:00:00', 'punch_type' => AttendancePunch::TYPE_IN, 'verify_mode' => 15,
        ]);
        AttendancePunch::create([
            'device_id' => $device->id, 'pin' => '9999',
            'punched_at' => '2026-09-14 09:05:00', 'punch_type' => AttendancePunch::TYPE_IN,
        ]);

        $this->getJson('/api/time-clock/punches')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/time-clock/punches?employee_id={$employee->id}&from=2026-09-14&to=2026-09-14")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_name', $employee->full_name)
            ->assertJsonPath('data.0.punch_type_label', 'Entrada')
            ->assertJsonPath('data.0.device', 'Mostrador');

        $this->getJson('/api/time-clock/devices')
            ->assertOk()
            ->assertJsonPath('data.0.serial_number', self::SN)
            ->assertJsonPath('data.0.punches_count', 2)
            ->assertJsonPath('data.0.is_online', false);

        $this->postJson('/api/time-clock/users', ['pin' => '1', 'name' => 'x'])->assertForbidden();
    }
}
