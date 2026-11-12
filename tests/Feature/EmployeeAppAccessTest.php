<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TimeClockUser;
use App\Models\User;
use App\Models\WorkerAccount;
use App\Services\WorkerAccessService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeAppAccessTest extends TestCase
{
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

    private function employee(bool $activeInTimeClock = true): Employee
    {
        $employee = Employee::factory()->create(['birthdate' => '1990-05-12']);

        TimeClockUser::create([
            'pin' => $employee->rfc,
            'name' => $employee->name,
            'employee_id' => $employee->id,
            'is_active' => $activeInTimeClock,
        ]);

        return $employee;
    }

    private function activeAccountWithSession(Employee $employee): WorkerAccount
    {
        $account = WorkerAccount::create([
            'employee_id' => $employee->id,
            'nip' => '482915',
            'activated_at' => now(),
        ]);

        app(WorkerAccessService::class)->startSession($account, [
            'installation_id' => 'install-1',
            'platform' => 'ios',
            'name' => 'iPhone de Juan',
        ]);

        return $account;
    }

    public function test_hr_issues_a_temporary_nip_that_is_shown_only_once(): void
    {
        $user = $this->actingAsUserWith('acceso_app.administrar');
        $employee = $this->employee();

        $response = $this->postJson("/api/employees/{$employee->id}/app-access/nip")
            ->assertCreated()
            ->assertJsonPath('data.status', 'temporary')
            ->assertJsonPath('data.nip_issued_by', $user->name);

        $nip = $response->json('nip');
        $account = $employee->workerAccount;

        $this->assertMatchesRegularExpression('/^\d{6}$/', $nip);
        $this->assertTrue(Hash::check($nip, $account->nip));
        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $account->nip_expires_at->timestamp, 5);

        Permission::findOrCreate('acceso_app.ver', 'web');
        $user->givePermissionTo('acceso_app.ver');

        $this->getJson("/api/employees/{$employee->id}/app-access")
            ->assertOk()
            ->assertJsonMissingPath('nip');
    }

    public function test_issuing_a_nip_needs_the_permission(): void
    {
        $this->actingAsUserWith('acceso_app.ver');
        $employee = $this->employee();

        $this->postJson("/api/employees/{$employee->id}/app-access/nip")->assertForbidden();

        $this->assertNull($employee->workerAccount);
    }

    public function test_a_nip_cannot_be_issued_without_an_active_time_clock_user(): void
    {
        $this->actingAsUserWith('acceso_app.administrar');
        $employee = $this->employee(activeInTimeClock: false);

        $this->postJson("/api/employees/{$employee->id}/app-access/nip")->assertUnprocessable();

        $this->assertNull($employee->workerAccount);
    }

    public function test_issuing_a_new_nip_closes_the_open_sessions(): void
    {
        $this->actingAsUserWith('acceso_app.administrar');
        $employee = $this->employee();
        $account = $this->activeAccountWithSession($employee);

        $this->postJson("/api/employees/{$employee->id}/app-access/nip")
            ->assertCreated()
            ->assertJsonPath('data.sessions', []);

        $this->assertSame(0, $account->tokens()->count());
        $this->assertTrue($account->fresh()->hasTemporaryNip());
    }

    public function test_hr_sees_the_status_and_the_open_sessions(): void
    {
        $this->actingAsUserWith('acceso_app.ver');
        $withoutAccount = $this->employee();
        $employee = $this->employee();
        $this->activeAccountWithSession($employee);

        $this->getJson("/api/employees/{$withoutAccount->id}/app-access")
            ->assertOk()
            ->assertJsonPath('data.status', 'none');

        $this->getJson("/api/employees/{$employee->id}/app-access")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.sessions.0.device_name', 'iPhone de Juan')
            ->assertJsonPath('data.sessions.0.platform', 'ios')
            ->assertJsonPath('data.sessions.0.push_enabled', false);
    }

    public function test_an_unchanged_temporary_nip_shows_as_expired(): void
    {
        $this->actingAsUserWith('acceso_app.ver', 'acceso_app.administrar');
        $employee = $this->employee();

        $this->postJson("/api/employees/{$employee->id}/app-access/nip")->assertCreated();

        $this->travel(73)->hours();

        $this->getJson("/api/employees/{$employee->id}/app-access")
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_hr_closes_every_session_of_the_worker(): void
    {
        $this->actingAsUserWith('acceso_app.administrar');
        $employee = $this->employee();
        $account = $this->activeAccountWithSession($employee);

        $this->deleteJson("/api/employees/{$employee->id}/app-access/sessions")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.sessions', []);

        $this->assertSame(0, $account->tokens()->count());
        $this->assertSame(0, $account->devices()->count());
    }
}
