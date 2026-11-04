<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShiftTest extends TestCase
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

    /**
     * Turno de oficina: lunes a viernes con comida, sábado corrido de medio día.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $weekday = fn (int $day) => [
            'weekday' => $day,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'break_start' => '14:00',
            'break_end' => '15:00',
        ];

        return array_merge([
            'name' => 'Oficina',
            'code' => 'OFI',
            'tolerance_minutes' => 10,
            'absence_after_minutes' => 30,
            'days' => [
                $weekday(1), $weekday(2), $weekday(3), $weekday(4), $weekday(5),
                ['weekday' => 6, 'start_time' => '09:00', 'end_time' => '13:00'],
            ],
        ], $overrides);
    }

    public function test_creates_a_shift_and_fills_missing_days_as_rest(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $response = $this->postJson('/api/shifts', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Oficina')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonCount(7, 'data.days')
            ->assertJsonPath('data.working_days_count', 6)
            // 5 días de 8 h efectivas + sábado de 4 h.
            ->assertJsonPath('data.weekly_minutes', 5 * 480 + 240);

        $days = collect($response->json('data.days'))->keyBy('weekday');

        $this->assertTrue($days[0]['is_rest_day'], 'El domingo no se mandó y debe quedar como descanso.');
        $this->assertNull($days[0]['start_time']);

        $this->assertSame('09:00', $days[1]['start_time']);
        $this->assertSame('14:00', $days[1]['break_start']);
        $this->assertTrue($days[1]['has_break']);
        $this->assertSame(60, $days[1]['break_minutes']);
        $this->assertSame(480, $days[1]['work_minutes']);
        $this->assertFalse($days[1]['crosses_midnight']);

        $this->assertFalse($days[6]['has_break'], 'El sábado es turno corrido.');
        $this->assertSame(240, $days[6]['work_minutes']);

        $this->assertDatabaseCount('shift_days', 7);
    }

    public function test_accepts_a_night_shift_whose_break_falls_after_midnight(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $response = $this->postJson('/api/shifts', $this->payload([
            'name' => 'Nocturno almacén',
            'code' => 'NOC',
            'days' => [[
                'weekday' => 1,
                'start_time' => '22:00',
                'end_time' => '06:00',
                'break_start' => '01:00',
                'break_end' => '01:30',
            ]],
        ]));

        $response->assertCreated();

        $monday = collect($response->json('data.days'))->firstWhere('weekday', 1);

        $this->assertTrue($monday['crosses_midnight']);
        $this->assertSame(30, $monday['break_minutes']);
        // 8 h de jornada menos media hora de comida.
        $this->assertSame(450, $monday['work_minutes']);
    }

    public function test_rejects_a_break_outside_the_working_hours(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $this->postJson('/api/shifts', $this->payload([
            'days' => [[
                'weekday' => 1,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start' => '18:30',
                'break_end' => '19:00',
            ]],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['days.0.break_start']);
    }

    public function test_rejects_a_break_with_only_one_bound(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $this->postJson('/api/shifts', $this->payload([
            'days' => [[
                'weekday' => 1,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start' => '14:00',
            ]],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['days.0.break_end']);
    }

    public function test_rejects_a_working_day_without_hours(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $this->postJson('/api/shifts', $this->payload([
            'days' => [['weekday' => 1]],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['days.0.start_time', 'days.0.end_time']);
    }

    public function test_rejects_a_shift_with_only_rest_days(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $this->postJson('/api/shifts', $this->payload([
            'days' => [['weekday' => 0, 'is_rest_day' => true]],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['days']);
    }

    public function test_rejects_repeated_weekdays(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $this->postJson('/api/shifts', $this->payload([
            'days' => [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '18:00'],
                ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '19:00'],
            ],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['days.0.weekday']);
    }

    public function test_rejects_an_absence_threshold_below_the_tolerance(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $this->postJson('/api/shifts', $this->payload([
            'tolerance_minutes' => 15,
            'absence_after_minutes' => 10,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['absence_after_minutes']);
    }

    public function test_rejects_a_duplicated_name(): void
    {
        $this->actingAsUserWith('turnos.crear');

        $this->postJson('/api/shifts', $this->payload())->assertCreated();

        $this->postJson('/api/shifts', $this->payload(['code' => 'OFI2']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_lists_shifts_with_search_and_active_filter(): void
    {
        $this->actingAsUserWith('turnos.crear', 'turnos.ver');

        $this->postJson('/api/shifts', $this->payload())->assertCreated();
        $this->postJson('/api/shifts', $this->payload([
            'name' => 'Nocturno', 'code' => 'NOC', 'is_active' => false,
        ]))->assertCreated();

        $this->getJson('/api/shifts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/shifts?only_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Oficina');

        $this->getJson('/api/shifts?search=noc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'NOC');
    }

    public function test_updates_a_shift_replacing_its_days(): void
    {
        $this->actingAsUserWith('turnos.crear', 'turnos.editar');

        $id = $this->postJson('/api/shifts', $this->payload())->json('data.id');

        $this->patchJson("/api/shifts/{$id}", [
            'tolerance_minutes' => 5,
            'days' => [
                ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '16:00'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.tolerance_minutes', 5)
            ->assertJsonPath('data.name', 'Oficina')
            ->assertJsonPath('data.working_days_count', 1)
            ->assertJsonPath('data.weekly_minutes', 480);

        $this->assertDatabaseCount('shift_days', 7);
    }

    public function test_updates_only_the_name_when_days_are_not_sent(): void
    {
        $this->actingAsUserWith('turnos.crear', 'turnos.editar');

        $id = $this->postJson('/api/shifts', $this->payload())->json('data.id');

        $this->patchJson("/api/shifts/{$id}", ['name' => 'Oficina matutino'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Oficina matutino')
            ->assertJsonPath('data.working_days_count', 6);
    }

    public function test_soft_deletes_a_shift_and_keeps_its_days(): void
    {
        $this->actingAsUserWith('turnos.crear', 'turnos.eliminar', 'turnos.ver');

        $id = $this->postJson('/api/shifts', $this->payload())->json('data.id');

        $this->deleteJson("/api/shifts/{$id}")->assertNoContent();

        $this->assertSoftDeleted('shifts', ['id' => $id]);
        $this->assertDatabaseCount('shift_days', 7);
        $this->getJson("/api/shifts/{$id}")->assertNotFound();
        $this->assertSame(0, Shift::count());
    }

    public function test_denies_access_without_the_permission(): void
    {
        $this->actingAsUserWith('turnos.ver');

        $this->postJson('/api/shifts', $this->payload())->assertForbidden();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/shifts')->assertUnauthorized();
    }
}
