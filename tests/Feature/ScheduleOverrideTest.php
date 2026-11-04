<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ScheduleOverrideTest extends TestCase
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

    public function test_creates_an_override_with_hours(): void
    {
        $user = $this->actingAsUserWith('turnos.asignar');
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/schedule-overrides", [
            'date' => '2026-12-24',
            'start_time' => '09:00',
            'end_time' => '14:00',
            'reason' => 'Medio día',
        ])->assertCreated()
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.work_minutes', 300)
            ->assertJsonPath('data.created_by.id', $user->id);
    }

    public function test_creates_a_rest_override_without_hours(): void
    {
        $this->actingAsUserWith('turnos.asignar');
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/schedule-overrides", [
            'date' => '2026-12-24',
            'is_rest_day' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_rest_day', true)
            ->assertJsonPath('data.start_time', null);
    }

    public function test_a_working_override_requires_hours(): void
    {
        $this->actingAsUserWith('turnos.asignar');
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/schedule-overrides", [
            'date' => '2026-12-24',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    public function test_posting_the_same_date_twice_replaces_the_override(): void
    {
        $this->actingAsUserWith('turnos.asignar');
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/schedule-overrides", [
            'date' => '2026-12-24', 'start_time' => '09:00', 'end_time' => '14:00',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/schedule-overrides", [
            'date' => '2026-12-24', 'start_time' => '10:00', 'end_time' => '15:00',
        ])->assertOk()
            ->assertJsonPath('data.start_time', '10:00');

        $this->assertDatabaseCount('schedule_overrides', 1);
    }

    public function test_lists_and_deletes_overrides(): void
    {
        $this->actingAsUserWith('turnos.asignar', 'turnos.ver');
        $employee = Employee::factory()->create();

        $id = $this->postJson("/api/employees/{$employee->id}/schedule-overrides", [
            'date' => '2026-12-24', 'start_time' => '09:00', 'end_time' => '14:00',
        ])->json('data.id');

        $this->getJson("/api/employees/{$employee->id}/schedule-overrides")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/schedule-overrides/{$id}")->assertNoContent();

        $this->assertDatabaseCount('schedule_overrides', 0);
    }

    public function test_requires_permission(): void
    {
        $this->actingAsUserWith('turnos.ver');
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/schedule-overrides", [
            'date' => '2026-12-24', 'start_time' => '09:00', 'end_time' => '14:00',
        ])->assertForbidden();
    }
}
