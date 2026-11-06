<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\SubDepartment;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeTerminationTest extends TestCase
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
        return $this->actingAsUserWith('empleados.eliminar', 'checador.administrar');
    }

    private function employeeWithTimeClockUser(): Employee
    {
        $employee = Employee::factory()->create();

        TimeClockUser::create([
            'pin' => $employee->rfc,
            'name' => $employee->full_name,
            'employee_id' => $employee->id,
        ]);

        return $employee;
    }

    private function terminate(Employee $employee, string $reason = 'Renuncia voluntaria'): TestResponse
    {
        return $this->deleteJson("/api/employees/{$employee->id}", ['termination_reason' => $reason]);
    }

    private function terminated(): Employee
    {
        $employee = $this->employeeWithTimeClockUser();
        $employee->timeClockUser->update(['is_active' => false]);
        $employee->update(['termination_reason' => 'Renuncia voluntaria']);
        $employee->delete();

        return $employee;
    }

    public function test_it_soft_deletes_the_employee_and_removes_it_from_active_devices(): void
    {
        $user = $this->actingAsHr();

        $employee = $this->employeeWithTimeClockUser();
        $active = TimeClockDevice::create(['serial_number' => 'ACTIVE-1', 'is_active' => true]);
        $inactive = TimeClockDevice::create(['serial_number' => 'OFF-1', 'is_active' => false]);

        $this->terminate($employee)
            ->assertOk()
            ->assertJsonPath('message', 'Trabajador dado de baja y retirado de 1 terminal(es).');

        $this->assertSoftDeleted($employee);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'termination_reason' => 'Renuncia voluntaria',
            'terminated_by' => $user->id,
        ]);
        $this->assertDatabaseHas('time_clock_users', [
            'employee_id' => $employee->id,
            'is_active' => false,
        ]);

        $command = $active->commands()->where('type', 'user_delete')->sole();
        $this->assertSame("DATA DELETE USERINFO PIN={$employee->rfc}", $command->command);
        $this->assertSame(0, $inactive->commands()->count());
    }

    public function test_it_requires_a_reason(): void
    {
        $this->actingAsHr();

        $employee = $this->employeeWithTimeClockUser();

        $this->terminate($employee, '')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('termination_reason');

        $this->assertNotSoftDeleted($employee);
    }

    public function test_it_closes_the_current_shift_and_drops_future_ones(): void
    {
        $this->actingAsHr();

        $employee = $this->employeeWithTimeClockUser();
        $shift = Shift::create(['name' => 'Matutino']);

        $current = $employee->shiftAssignments()->create([
            'shift_id' => $shift->id,
            'starts_on' => today()->subMonth(),
        ]);
        $future = $employee->shiftAssignments()->create([
            'shift_id' => $shift->id,
            'starts_on' => today()->addMonth(),
        ]);

        $this->terminate($employee)->assertOk();

        $this->assertTrue($current->refresh()->ends_on->isSameDay(today()));
        $this->assertSoftDeleted($future);
    }

    public function test_the_areas_it_managed_are_left_without_a_manager(): void
    {
        $this->actingAsHr();

        $employee = $this->employeeWithTimeClockUser();
        $department = Department::create(['name' => 'Almacén', 'manager_id' => $employee->id]);
        $subDepartment = SubDepartment::create([
            'department_id' => $department->id,
            'name' => 'Recibo',
            'manager_id' => $employee->id,
        ]);

        $this->terminate($employee)->assertOk();

        $this->assertNull($department->refresh()->manager_id);
        $this->assertNull($subDepartment->refresh()->manager_id);
    }

    public function test_a_terminated_employee_only_appears_in_the_terminated_list(): void
    {
        $user = $this->actingAsUserWith('empleados.eliminar', 'checador.administrar', 'empleados.ver');

        $employee = $this->employeeWithTimeClockUser();
        $active = Employee::factory()->create();

        $this->terminate($employee)->assertOk();

        $this->getJson("/api/employees/{$employee->id}")->assertNotFound();
        $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonMissingPath('data.0.terminated_at');

        $this->getJson('/api/employees?status=terminated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee->id)
            ->assertJsonPath('data.0.termination_reason', 'Renuncia voluntaria')
            ->assertJsonPath('data.0.terminated_by.id', $user->id);
    }

    public function test_a_user_cannot_terminate_their_own_employee(): void
    {
        // Solo un rol sin recorte llega aquí: OrgChartScope ya excluye al
        // propio trabajador de los demás.
        $user = $this->actingAsHr();
        $user->assignRole(Role::findOrCreate(User::ROLE_ADMIN, 'web'));
        $employee = $this->employeeWithTimeClockUser();
        $user->update(['employee_id' => $employee->id]);

        $this->terminate($employee)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No puedes darte de baja a ti mismo.');

        $this->assertNotSoftDeleted($employee);
    }

    public function test_terminating_requires_both_permissions(): void
    {
        $employee = $this->employeeWithTimeClockUser();

        $this->actingAsUserWith('empleados.eliminar');
        $this->terminate($employee)->assertForbidden();

        $this->actingAsUserWith('checador.administrar');
        $this->terminate($employee)->assertForbidden();

        $this->assertNotSoftDeleted($employee);
    }

    public function test_it_restores_the_employee_and_sends_it_back_to_active_devices(): void
    {
        $this->actingAsUserWith('empleados.reactivar', 'checador.administrar');

        $employee = $this->terminated();
        $device = TimeClockDevice::create(['serial_number' => 'ACTIVE-1', 'is_active' => true]);

        $this->postJson("/api/employees/{$employee->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('message', 'Trabajador reactivado y enviado a 1 terminal(es). Asígnale un turno y vuelve a enrolar su rostro en el equipo.');

        $this->assertNotSoftDeleted($employee);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'termination_reason' => null,
            'terminated_by' => null,
        ]);
        $this->assertDatabaseHas('time_clock_users', [
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $command = $device->commands()->where('type', 'user_upsert')->sole();
        $this->assertStringContainsString("PIN={$employee->rfc}", $command->command);
    }

    public function test_restoring_requires_the_reactivate_permission(): void
    {
        $this->actingAsUserWith('empleados.eliminar', 'checador.administrar');

        $employee = $this->terminated();

        $this->postJson("/api/employees/{$employee->id}/restore")->assertForbidden();

        $this->assertSoftDeleted($employee);
    }

    public function test_only_terminated_employees_can_be_restored(): void
    {
        $this->actingAsUserWith('empleados.reactivar', 'checador.administrar');

        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/restore")->assertNotFound();
    }
}
