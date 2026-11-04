<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeShiftTest extends TestCase
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

    private function shift(string $name, bool $active = true): Shift
    {
        $shift = Shift::create(['name' => $name, 'is_active' => $active]);
        $shift->syncDays([
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '18:00'],
        ]);

        return $shift;
    }

    public function test_assigns_a_shift_and_it_shows_as_the_employee_current_shift(): void
    {
        $this->actingAsUserWith('turnos.asignar', 'turnos.ver', 'empleados.ver');

        $employee = Employee::factory()->create();
        $shift = $this->shift('Matutino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $shift->id,
            'starts_on' => '2026-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.shift.name', 'Matutino')
            ->assertJsonPath('data.ends_on', null)
            ->assertJsonPath('data.is_current', true);

        $this->getJson("/api/employees/{$employee->id}/shift-assignments")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Sin ?search: el scope de búsqueda usa CONCAT_WS, que SQLite no tiene.
        $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonPath('data.0.id', $employee->id)
            ->assertJsonPath('data.0.current_shift.shift.name', 'Matutino');
    }

    public function test_a_new_open_assignment_closes_the_previous_one_the_day_before(): void
    {
        $this->actingAsUserWith('turnos.asignar', 'turnos.ver');

        $employee = Employee::factory()->create();
        $morning = $this->shift('Matutino');
        $evening = $this->shift('Vespertino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $morning->id, 'starts_on' => '2026-01-01',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $evening->id, 'starts_on' => '2026-03-01',
        ])->assertCreated();

        $history = $this->getJson("/api/employees/{$employee->id}/shift-assignments")
            ->assertJsonCount(2, 'data')
            ->json('data');

        // Orden descendente por inicio: primero el vespertino.
        $this->assertSame('Vespertino', $history[0]['shift']['name']);
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame('Matutino', $history[1]['shift']['name']);
        $this->assertSame('2026-02-28', $history[1]['ends_on']);
        $this->assertFalse($history[1]['is_current']);
    }

    public function test_a_temporary_assignment_resumes_the_previous_shift_afterwards(): void
    {
        $this->actingAsUserWith('turnos.asignar', 'turnos.ver');

        $employee = Employee::factory()->create();
        $morning = $this->shift('Matutino');
        $evening = $this->shift('Vespertino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $morning->id, 'starts_on' => '2026-01-01',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $evening->id, 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-15',
        ])->assertCreated();

        $history = collect($this->getJson("/api/employees/{$employee->id}/shift-assignments")
            ->assertJsonCount(3, 'data')
            ->json('data'))->sortBy('starts_on')->values();

        $this->assertSame(['2026-01-01', '2026-02-28'], [$history[0]['starts_on'], $history[0]['ends_on']]);
        $this->assertSame(['2026-03-01', '2026-03-15'], [$history[1]['starts_on'], $history[1]['ends_on']]);
        $this->assertSame(['2026-03-16', null], [$history[2]['starts_on'], $history[2]['ends_on']]);
        $this->assertSame('Matutino', $history[2]['shift']['name']);
    }

    public function test_rejects_an_overlap_with_a_closed_period(): void
    {
        $this->actingAsUserWith('turnos.asignar');

        $employee = Employee::factory()->create();
        $morning = $this->shift('Matutino');
        $evening = $this->shift('Vespertino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $morning->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $evening->id, 'starts_on' => '2026-01-15',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('employee_shifts', 1);
    }

    public function test_rejects_a_start_before_the_hire_date(): void
    {
        $this->actingAsUserWith('turnos.asignar');

        $employee = Employee::factory()->create(['hire_date' => '2024-01-15']);
        $shift = $this->shift('Matutino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $shift->id, 'starts_on' => '2023-12-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);
    }

    public function test_rejects_an_inactive_shift_and_an_end_before_the_start(): void
    {
        $this->actingAsUserWith('turnos.asignar');

        $employee = Employee::factory()->create();
        $inactive = $this->shift('Viejo', active: false);
        $active = $this->shift('Matutino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $inactive->id, 'starts_on' => '2026-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['shift_id']);

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $active->id, 'starts_on' => '2026-01-10', 'ends_on' => '2026-01-05',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on']);
    }

    public function test_bulk_assigns_and_lists_the_employees_in_the_shift(): void
    {
        $this->actingAsUserWith('turnos.asignar', 'turnos.ver');

        $employees = Employee::factory()->count(3)->create();
        $shift = $this->shift('Matutino');

        $this->postJson("/api/shifts/{$shift->id}/assignments", [
            'employee_ids' => $employees->pluck('id')->all(),
            'starts_on' => '2026-01-01',
        ])->assertCreated()
            ->assertJsonCount(3, 'data');

        $this->getJson("/api/shifts/{$shift->id}/assignments")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.is_current', true);

        // Antes de la fecha de inicio nadie estaba en el turno.
        $this->getJson("/api/shifts/{$shift->id}/assignments?date=2025-12-31")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/shifts/{$shift->id}")
            ->assertOk()
            ->assertJsonPath('data.current_employees_count', 3);
    }

    public function test_bulk_assignment_is_all_or_nothing(): void
    {
        $this->actingAsUserWith('turnos.asignar');

        $ok = Employee::factory()->create();
        $hiredLater = Employee::factory()->create(['hire_date' => '2026-06-01']);
        $shift = $this->shift('Matutino');

        $this->postJson("/api/shifts/{$shift->id}/assignments", [
            'employee_ids' => [$ok->id, $hiredLater->id],
            'starts_on' => '2026-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_ids.1']);

        $this->assertDatabaseCount('employee_shifts', 0);
    }

    public function test_a_shift_with_employees_cannot_be_deleted(): void
    {
        $this->actingAsUserWith('turnos.asignar', 'turnos.eliminar');

        $employee = Employee::factory()->create();
        $shift = $this->shift('Matutino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $shift->id, 'starts_on' => '2026-01-01',
        ])->assertCreated();

        $this->deleteJson("/api/shifts/{$shift->id}")->assertUnprocessable();

        // Una asignación ya terminada es historia y no estorba.
        $shift->assignments()->update(['ends_on' => '2026-01-31']);

        $this->deleteJson("/api/shifts/{$shift->id}")->assertNoContent();
    }

    public function test_updates_an_assignment_and_rejects_overlaps_on_update(): void
    {
        $this->actingAsUserWith('turnos.asignar');

        $employee = Employee::factory()->create();
        $morning = $this->shift('Matutino');
        $evening = $this->shift('Vespertino');

        $first = $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $morning->id, 'starts_on' => '2026-01-01',
        ])->json('data.id');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $evening->id, 'starts_on' => '2026-03-01',
        ])->assertCreated();

        // El matutino quedó cerrado el 28 de febrero; recortarlo es válido.
        $this->patchJson("/api/shift-assignments/{$first}", ['ends_on' => '2026-02-15', 'notes' => 'Ajuste'])
            ->assertOk()
            ->assertJsonPath('data.ends_on', '2026-02-15')
            ->assertJsonPath('data.notes', 'Ajuste');

        // Extenderlo sobre el vespertino no.
        $this->patchJson("/api/shift-assignments/{$first}", ['ends_on' => '2026-03-10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->patchJson("/api/shift-assignments/{$first}", ['ends_on' => '2025-12-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on']);
    }

    public function test_deletes_an_assignment(): void
    {
        $this->actingAsUserWith('turnos.asignar');

        $employee = Employee::factory()->create();
        $shift = $this->shift('Matutino');

        $id = $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $shift->id, 'starts_on' => '2026-01-01',
        ])->json('data.id');

        $this->deleteJson("/api/shift-assignments/{$id}")->assertNoContent();

        $this->assertDatabaseCount('employee_shifts', 0);
    }

    public function test_denies_assignment_without_the_permission(): void
    {
        $this->actingAsUserWith('turnos.ver');

        $employee = Employee::factory()->create();
        $shift = $this->shift('Matutino');

        $this->postJson("/api/employees/{$employee->id}/shift-assignments", [
            'shift_id' => $shift->id, 'starts_on' => '2026-01-01',
        ])->assertForbidden();
    }
}
