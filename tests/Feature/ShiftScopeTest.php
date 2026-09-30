<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\SubDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Árbol de prueba:
 *
 *   Ventas (área, responsable: gerente)
 *   ├── Mostrador (responsable: supervisorA) → trabajador1
 *   │   └── Turno noche (sin responsable)   → trabajador2
 *   └── Rutas (responsable: supervisorB)    → trabajador3
 *   Almacén (otra área)                     → ajeno
 */
class ShiftScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Employee> */
    private array $people = [];

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['turnos.asignar', 'turnos.ver', 'empleados.ver'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (User::ASSIGNABLE_ROLES as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(['turnos.asignar', 'turnos.ver', 'empleados.ver']);
        }

        $sales = Department::create(['name' => 'Ventas']);
        $counter = SubDepartment::create(['department_id' => $sales->id, 'name' => 'Mostrador']);
        $night = SubDepartment::create(['department_id' => $sales->id, 'parent_id' => $counter->id, 'name' => 'Turno noche']);
        $routes = SubDepartment::create(['department_id' => $sales->id, 'name' => 'Rutas']);

        $warehouse = Department::create(['name' => 'Almacén']);
        $shelves = SubDepartment::create(['department_id' => $warehouse->id, 'name' => 'Anaqueles']);

        $this->people = [
            'gerente' => Employee::factory()->create(['sub_department_id' => $counter->id]),
            'supervisorA' => Employee::factory()->create(['sub_department_id' => $counter->id]),
            'trabajador1' => Employee::factory()->create(['sub_department_id' => $counter->id]),
            'trabajador2' => Employee::factory()->create(['sub_department_id' => $night->id]),
            'supervisorB' => Employee::factory()->create(['sub_department_id' => $routes->id]),
            'trabajador3' => Employee::factory()->create(['sub_department_id' => $routes->id]),
            'ajeno' => Employee::factory()->create(['sub_department_id' => $shelves->id]),
        ];

        $sales->update(['manager_id' => $this->people['gerente']->id]);
        $counter->update(['manager_id' => $this->people['supervisorA']->id]);
        $routes->update(['manager_id' => $this->people['supervisorB']->id]);

        $this->shift = Shift::create(['name' => 'Matutino', 'is_active' => true]);
        $this->shift->syncDays([['weekday' => 1, 'start_time' => '09:00', 'end_time' => '18:00']]);
    }

    private function actingAsLinkedTo(string $person, string $role = User::ROLE_AREA_MANAGER): User
    {
        $user = User::factory()->create(['employee_id' => $this->people[$person]->id]);
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    private function assign(string $person)
    {
        return $this->postJson("/api/employees/{$this->people[$person]->id}/shift-assignments", [
            'shift_id' => $this->shift->id,
            'starts_on' => '2026-01-01',
        ]);
    }

    public function test_a_manager_reaches_the_whole_tree_below_them(): void
    {
        $this->actingAsLinkedTo('gerente');

        foreach (['supervisorA', 'trabajador1', 'trabajador2', 'supervisorB', 'trabajador3'] as $person) {
            $this->assign($person)->assertCreated();
        }
    }

    public function test_a_manager_cannot_assign_outside_their_tree_nor_to_themselves(): void
    {
        $this->actingAsLinkedTo('gerente');

        $this->assign('ajeno')->assertForbidden();
        $this->assign('gerente')->assertForbidden();
    }

    public function test_a_supervisor_reaches_nested_sub_areas_but_not_siblings(): void
    {
        $this->actingAsLinkedTo('supervisorA');

        $this->assign('trabajador2')->assertCreated();
        $this->assign('trabajador3')->assertForbidden();
    }

    public function test_a_worker_who_heads_nothing_has_no_one_in_scope(): void
    {
        $this->actingAsLinkedTo('trabajador1');

        $this->assign('trabajador2')->assertForbidden();
    }

    public function test_hr_is_not_limited_by_the_org_chart(): void
    {
        $this->actingAsLinkedTo('trabajador1', User::ROLE_HR);

        $this->assign('ajeno')->assertCreated();
    }

    public function test_editing_or_deleting_an_assignment_respects_the_scope(): void
    {
        $this->actingAsLinkedTo('trabajador1', User::ROLE_HR);
        $id = $this->assign('ajeno')->json('data.id');

        $this->actingAsLinkedTo('gerente');

        $this->patchJson("/api/shift-assignments/{$id}", ['notes' => 'x'])->assertForbidden();
        $this->deleteJson("/api/shift-assignments/{$id}")->assertForbidden();
    }

    public function test_bulk_assignment_flags_whoever_is_out_of_scope(): void
    {
        $this->actingAsLinkedTo('supervisorB');

        $this->postJson("/api/shifts/{$this->shift->id}/assignments", [
            'employee_ids' => [$this->people['trabajador3']->id, $this->people['ajeno']->id],
            'starts_on' => '2026-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('employee_ids.1')
            ->assertJsonMissingValidationErrors('employee_ids.0');
    }

    public function test_the_employee_detail_says_whether_a_shift_can_be_assigned(): void
    {
        $this->actingAsLinkedTo('supervisorB');

        $this->getJson("/api/employees/{$this->people['trabajador3']->id}")
            ->assertJsonPath('data.can_assign_shift', true);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('empleados.ver');
        Sanctum::actingAs($viewer);

        $this->getJson("/api/employees/{$this->people['ajeno']->id}")
            ->assertJsonPath('data.can_assign_shift', false);
    }

    public function test_the_employee_list_only_shows_the_tree_below_the_manager(): void
    {
        $this->actingAsLinkedTo('supervisorA');

        $ids = $this->getJson('/api/employees?per_page=100')->assertOk()->json('data.*.id');

        $this->assertEqualsCanonicalizing(
            [$this->people['gerente']->id, $this->people['trabajador1']->id, $this->people['trabajador2']->id],
            $ids,
        );
    }

    public function test_an_employee_outside_the_tree_cannot_be_opened(): void
    {
        $this->actingAsLinkedTo('supervisorB');

        $this->getJson("/api/employees/{$this->people['trabajador3']->id}")->assertOk();
        $this->getJson("/api/employees/{$this->people['ajeno']->id}")->assertForbidden();
        $this->getJson("/api/employees/{$this->people['ajeno']->id}/shift-assignments")->assertForbidden();
        $this->getJson("/api/employees/{$this->people['supervisorB']->id}")->assertForbidden();
    }

    public function test_a_sub_area_head_located_elsewhere_still_hangs_from_the_area_manager(): void
    {
        // Ajeno está ubicado en Almacén, pero encabeza Rutas, que es de Ventas.
        SubDepartment::where('name', 'Rutas')->update(['manager_id' => $this->people['ajeno']->id]);

        $this->actingAsLinkedTo('gerente');

        $this->assign('ajeno')->assertCreated();
        $this->getJson("/api/employees/{$this->people['ajeno']->id}")->assertOk();
        $this->assertContains(
            $this->people['ajeno']->id,
            $this->getJson('/api/employees?per_page=100')->json('data.*.id'),
        );

        // Sigue sin depender del supervisor de Mostrador.
        $this->actingAsLinkedTo('supervisorA');
        $this->assign('ajeno')->assertForbidden();
    }

    public function test_hr_sees_the_whole_staff(): void
    {
        $this->actingAsLinkedTo('trabajador1', User::ROLE_HR);

        $this->getJson('/api/employees?per_page=100')
            ->assertOk()
            ->assertJsonCount(count($this->people), 'data');
    }
}
