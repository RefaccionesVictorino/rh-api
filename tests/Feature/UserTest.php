<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::ASSIGNABLE_ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function actingAsAdmin(): User
    {
        foreach (['usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $admin->givePermissionTo(['usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar']);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function employeeWithArea(): Employee
    {
        $department = Department::create(['name' => 'Ventas']);
        $subDepartment = SubDepartment::create(['department_id' => $department->id, 'name' => 'Mostrador']);

        return Employee::factory()->create(['sub_department_id' => $subDepartment->id]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ana López',
            'email' => 'ana@example.com',
            'password' => 'secreto123',
            'role' => User::ROLE_AREA_MANAGER,
            ...$overrides,
        ];
    }

    public function test_creates_a_user_linked_to_an_employee_with_area(): void
    {
        $this->actingAsAdmin();
        $employee = $this->employeeWithArea();

        $this->postJson('/api/users', $this->payload(['employee_id' => $employee->id]))
            ->assertCreated()
            ->assertJsonPath('data.role', User::ROLE_AREA_MANAGER)
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.has_access', true);
    }

    public function test_an_admin_can_exist_without_an_employee(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/users', $this->payload(['role' => User::ROLE_ADMIN]))
            ->assertCreated()
            ->assertJsonPath('data.employee_id', null);
    }

    public function test_other_roles_require_an_employee(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/users', $this->payload(['role' => User::ROLE_HR]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_rejects_an_employee_without_area(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->postJson('/api/users', $this->payload(['employee_id' => $employee->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_an_employee_has_at_most_one_active_user(): void
    {
        $this->actingAsAdmin();
        $employee = $this->employeeWithArea();

        $this->postJson('/api/users', $this->payload(['employee_id' => $employee->id]))->assertCreated();

        $this->postJson('/api/users', $this->payload([
            'email' => 'otra@example.com',
            'employee_id' => $employee->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    public function test_switching_away_from_admin_requires_an_employee(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $user->assignRole(User::ROLE_ADMIN);

        $this->putJson("/api/users/{$user->id}", ['role' => User::ROLE_HR])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');

        $employee = $this->employeeWithArea();

        $this->putJson("/api/users/{$user->id}", ['role' => User::ROLE_HR, 'employee_id' => $employee->id])
            ->assertOk()
            ->assertJsonPath('data.role', User::ROLE_HR);
    }

    public function test_deleting_a_user_is_logical_and_frees_the_employee(): void
    {
        $this->actingAsAdmin();
        $employee = $this->employeeWithArea();

        $id = $this->postJson('/api/users', $this->payload(['employee_id' => $employee->id]))->json('data.id');

        $this->deleteJson("/api/users/{$id}")->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $id]);

        $this->postJson('/api/users', $this->payload(['employee_id' => $employee->id]))->assertCreated();
    }

    public function test_cannot_delete_yourself(): void
    {
        $admin = $this->actingAsAdmin();

        $this->deleteJson("/api/users/{$admin->id}")->assertUnprocessable();
    }

    public function test_a_user_whose_employee_loses_the_area_loses_access(): void
    {
        $employee = $this->employeeWithArea();
        $user = User::factory()->create(['employee_id' => $employee->id, 'password' => 'secreto123']);
        $user->assignRole(User::ROLE_AREA_MANAGER);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secreto123'])->assertOk();

        $employee->update(['sub_department_id' => null]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secreto123'])->assertForbidden();

        Sanctum::actingAs($user->refresh());
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
