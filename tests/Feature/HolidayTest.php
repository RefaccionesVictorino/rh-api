<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HolidayTest extends TestCase
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

    public function test_creates_a_mandatory_rest_holiday(): void
    {
        $this->actingAsUserWith('festivos.crear');

        $this->postJson('/api/holidays', [
            'date' => '2026-12-25',
            'name' => 'Navidad',
            'observance' => Holiday::REST,
            'is_mandatory' => true,
        ])->assertCreated()
            ->assertJsonPath('data.observance_label', 'Descanso')
            ->assertJsonPath('data.start_time', null)
            ->assertJsonPath('data.work_minutes', 0);
    }

    public function test_creates_a_holiday_with_special_hours(): void
    {
        $this->actingAsUserWith('festivos.crear');

        $this->postJson('/api/holidays', [
            'date' => '2026-12-24',
            'name' => 'Nochebuena',
            'observance' => Holiday::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ])->assertCreated()
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '14:00')
            ->assertJsonPath('data.work_minutes', 300);
    }

    public function test_special_hours_require_a_schedule(): void
    {
        $this->actingAsUserWith('festivos.crear');

        $this->postJson('/api/holidays', [
            'date' => '2026-12-24',
            'name' => 'Nochebuena',
            'observance' => Holiday::SPECIAL_HOURS,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    public function test_rejects_a_break_outside_the_special_hours(): void
    {
        $this->actingAsUserWith('festivos.crear');

        $this->postJson('/api/holidays', [
            'date' => '2026-12-24',
            'name' => 'Nochebuena',
            'observance' => Holiday::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
            'break_start' => '15:00',
            'break_end' => '15:30',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['break_start']);
    }

    public function test_rejects_a_duplicated_date(): void
    {
        $this->actingAsUserWith('festivos.crear');

        $payload = ['date' => '2026-12-25', 'name' => 'Navidad', 'observance' => Holiday::REST];

        $this->postJson('/api/holidays', $payload)->assertCreated();
        $this->postJson('/api/holidays', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_switching_to_rest_clears_the_special_hours(): void
    {
        $this->actingAsUserWith('festivos.crear', 'festivos.editar');

        $id = $this->postJson('/api/holidays', [
            'date' => '2026-12-24',
            'name' => 'Nochebuena',
            'observance' => Holiday::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ])->json('data.id');

        $this->patchJson("/api/holidays/{$id}", ['observance' => Holiday::REST])
            ->assertOk()
            ->assertJsonPath('data.start_time', null)
            ->assertJsonPath('data.end_time', null);
    }

    public function test_updating_only_the_name_keeps_the_special_hours(): void
    {
        $this->actingAsUserWith('festivos.crear', 'festivos.editar');

        $id = $this->postJson('/api/holidays', [
            'date' => '2026-12-24',
            'name' => 'Nochebuena',
            'observance' => Holiday::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ])->json('data.id');

        $this->patchJson("/api/holidays/{$id}", ['name' => '24 de diciembre'])
            ->assertOk()
            ->assertJsonPath('data.name', '24 de diciembre')
            ->assertJsonPath('data.start_time', '09:00');
    }

    public function test_lists_the_current_year_by_default(): void
    {
        $this->actingAsUserWith('festivos.ver');

        Holiday::create(['date' => now()->startOfYear()->toDateString(), 'name' => 'Año Nuevo', 'observance' => Holiday::REST]);
        Holiday::create(['date' => now()->addYear()->startOfYear()->toDateString(), 'name' => 'Año Nuevo siguiente', 'observance' => Holiday::REST]);

        $this->getJson('/api/holidays')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Año Nuevo');

        $this->getJson('/api/holidays?year='.now()->addYear()->year)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Año Nuevo siguiente');
    }

    public function test_requires_permission(): void
    {
        $this->actingAsUserWith('festivos.ver');

        $this->postJson('/api/holidays', [
            'date' => '2026-12-25', 'name' => 'Navidad', 'observance' => Holiday::REST,
        ])->assertForbidden();
    }
}
