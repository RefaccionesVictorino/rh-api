<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Models\TimeClockUser;
use App\Models\User;
use App\Models\WorkerAccount;
use App\Models\WorkerDevice;
use App\Services\EmployeeTerminationService;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerAuthTest extends TestCase
{
    /** En producción el PIN del checador es el RFC. */
    private const RFC = 'GOMJ900512AB1';

    private const BIRTHDATE = '1990-05-12';

    private const NIP = '482915';

    private const PUSH_TOKEN = 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]';

    private function employee(string $rfc = self::RFC, bool $activeInTimeClock = true): Employee
    {
        $employee = Employee::factory()->create(['rfc' => $rfc, 'birthdate' => self::BIRTHDATE]);

        TimeClockUser::create([
            'pin' => $rfc,
            'name' => $employee->name,
            'employee_id' => $employee->id,
            'is_active' => $activeInTimeClock,
        ]);

        return $employee;
    }

    private function account(?Employee $employee = null, bool $temporary = false): WorkerAccount
    {
        return WorkerAccount::create([
            'employee_id' => ($employee ?? $this->employee())->id,
            'nip' => self::NIP,
            'nip_expires_at' => $temporary ? now()->addHours(72) : null,
            'activated_at' => $temporary ? null : now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function credentials(array $overrides = []): array
    {
        return [
            'rfc' => self::RFC,
            'nip' => self::NIP,
            'device' => [
                'installation_id' => 'install-1',
                'platform' => 'android',
                'name' => 'Galaxy A54',
                'os_version' => '15',
                'app_version' => '1.0.0',
            ],
            ...$overrides,
        ];
    }

    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/worker/login', $this->credentials($overrides));
    }

    /** Cada petición debe resolver su propio token, no el usuario de la anterior. */
    private function asWorker(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_a_worker_logs_in_with_rfc_and_nip(): void
    {
        $account = $this->account();

        $response = $this->login()
            ->assertOk()
            ->assertJsonPath('worker.full_name', $account->employee->full_name)
            ->assertJsonStructure(['token', 'expires_at']);

        $this->assertNotNull($account->fresh()->last_login_at);
        $this->assertDatabaseHas('worker_devices', [
            'worker_account_id' => $account->id,
            'installation_id' => 'install-1',
            'platform' => 'android',
            'device_name' => 'Galaxy A54',
        ]);

        $this->asWorker($response->json('token'))
            ->getJson('/api/worker/me')
            ->assertOk()
            ->assertJsonPath('data.full_name', $account->employee->full_name);
    }

    public function test_me_returns_the_profile_of_the_worker(): void
    {
        $department = Department::create(['name' => 'Ventas']);
        $subDepartment = SubDepartment::create(['department_id' => $department->id, 'name' => 'Mostrador']);

        $account = $this->account();
        $account->employee->update(['sub_department_id' => $subDepartment->id]);

        $this->asWorker($this->login()->json('token'))
            ->getJson('/api/worker/me')
            ->assertOk()
            ->assertJsonPath('data.rfc', self::RFC)
            ->assertJsonPath('data.hire_date', $account->employee->hire_date->toDateString())
            ->assertJsonPath('data.years_of_service', $account->employee->yearsOfServiceOn())
            ->assertJsonPath('data.sub_department', 'Mostrador')
            ->assertJsonPath('data.department', 'Ventas')
            ->assertJsonPath('data.birthdate', self::BIRTHDATE)
            ->assertJsonPath('data.curp', $account->employee->curp)
            ->assertJsonPath('data.nss', $account->employee->nss)
            ->assertJsonPath('data.personal_email', $account->employee->personal_email)
            ->assertJsonPath('data.personal_phone', $account->employee->personal_phone)
            ->assertJsonPath('data.address', $account->employee->address)
            ->assertJsonPath('data.municipality', $account->employee->municipality)
            ->assertJsonPath('data.postal_code', $account->employee->postal_code);
    }

    public function test_the_session_lasts_ninety_days(): void
    {
        $this->account();

        $token = $this->login()->json('token');

        $this->travel(91)->days();

        $this->asWorker($token)->getJson('/api/worker/me')->assertUnauthorized();
    }

    public function test_the_rfc_is_accepted_in_lowercase_and_with_spaces(): void
    {
        $this->account();

        $this->login(['rfc' => ' gomj 900512 ab1 '])->assertOk();
    }

    public function test_a_wrong_nip_and_an_unknown_rfc_get_the_same_message(): void
    {
        $this->account();

        $this->login(['nip' => '000001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc' => 'RFC o NIP incorrectos.'])
            ->assertJsonMissingPath('token');

        $this->login(['rfc' => 'XAXX010101000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc' => 'RFC o NIP incorrectos.']);
    }

    public function test_repeated_failures_lock_the_rfc(): void
    {
        $this->account();

        foreach (range(1, 5) as $attempt) {
            $this->login(['nip' => '000001']);
        }

        $this->login()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc' => 'Demasiados intentos fallidos']);

        $this->travel(16)->minutes();

        $this->login()->assertOk();
    }

    public function test_an_inactive_time_clock_user_cannot_log_in(): void
    {
        $this->account($this->employee(activeInTimeClock: false));

        $this->login()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc' => 'RFC o NIP incorrectos.']);
    }

    public function test_a_temporary_nip_asks_for_a_new_one_before_opening_a_session(): void
    {
        $account = $this->account(temporary: true);

        $this->login()
            ->assertStatus(409)
            ->assertJsonPath('code', 'nip_change_required')
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $account->tokens()->count());

        $this->login(['new_nip' => '730418'])->assertOk()->assertJsonStructure(['token']);

        $account->refresh();
        $this->assertFalse($account->hasTemporaryNip());
        $this->assertNotNull($account->activated_at);

        $this->login()->assertUnprocessable();
        $this->login(['nip' => '730418'])->assertOk();
    }

    public function test_the_new_nip_cannot_be_easy_to_guess(): void
    {
        $this->account(temporary: true);

        foreach (['111111', '123456', '987654', '120590', '900512', '12345'] as $weakNip) {
            $this->login(['new_nip' => $weakNip])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['new_nip']);
        }

        $this->login(['new_nip' => self::NIP])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_nip' => 'distinto del temporal']);
    }

    public function test_an_expired_temporary_nip_is_rejected(): void
    {
        $this->account(temporary: true);

        $this->travel(73)->hours();

        $this->login(['new_nip' => '730418'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nip' => 'venció']);
    }

    public function test_logging_in_again_from_the_same_phone_replaces_its_session(): void
    {
        $account = $this->account();

        $oldToken = $this->login()->json('token');
        $this->login()->assertOk();

        $this->assertSame(1, $account->tokens()->count());
        $this->assertSame(1, $account->devices()->count());
        $this->asWorker($oldToken)->getJson('/api/worker/me')->assertUnauthorized();
    }

    public function test_another_worker_on_the_same_phone_takes_over_the_installation(): void
    {
        $first = $this->account();
        $second = $this->account($this->employee('LOPM850101XY2'));

        $this->login()->assertOk();
        $this->login(['rfc' => 'LOPM850101XY2'])->assertOk();

        $this->assertSame(0, $first->tokens()->count());
        $this->assertSame(1, $second->devices()->count());
    }

    public function test_logout_closes_only_the_session_of_that_phone(): void
    {
        $account = $this->account();

        $token = $this->login()->json('token');
        $this->login(['device' => ['installation_id' => 'install-2', 'platform' => 'ios']])->assertOk();

        $this->asWorker($token)->postJson('/api/worker/logout')->assertOk();

        $this->assertSame(1, $account->tokens()->count());
        $this->assertDatabaseMissing('worker_devices', ['installation_id' => 'install-1']);
        $this->asWorker($token)->getJson('/api/worker/me')->assertUnauthorized();
    }

    public function test_the_worker_registers_the_push_token_of_the_phone(): void
    {
        $account = $this->account();
        $token = $this->login()->json('token');

        $this->asWorker($token)
            ->putJson('/api/worker/device', ['expo_push_token' => self::PUSH_TOKEN, 'app_version' => '1.1.0'])
            ->assertOk();

        $this->assertSame([self::PUSH_TOKEN], $account->routeNotificationForExpo());
        $this->assertDatabaseHas('worker_devices', ['installation_id' => 'install-1', 'app_version' => '1.1.0']);

        $this->asWorker($token)->putJson('/api/worker/device', ['expo_push_token' => null])->assertOk();

        $this->assertSame([], $account->routeNotificationForExpo());
    }

    public function test_a_push_token_that_is_not_from_expo_is_rejected(): void
    {
        $this->account();

        $this->asWorker($this->login()->json('token'))
            ->putJson('/api/worker/device', ['expo_push_token' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expo_push_token']);
    }

    public function test_a_push_token_belongs_only_to_the_latest_device_that_reports_it(): void
    {
        $this->account();

        $oldToken = $this->login()->json('token');
        $newToken = $this->login(['device' => ['installation_id' => 'install-2', 'platform' => 'android']])->json('token');

        $this->asWorker($oldToken)->putJson('/api/worker/device', ['expo_push_token' => self::PUSH_TOKEN]);
        $this->asWorker($newToken)->putJson('/api/worker/device', ['expo_push_token' => self::PUSH_TOKEN])->assertOk();

        $this->assertSame(1, WorkerDevice::where('expo_push_token', self::PUSH_TOKEN)->count());
        $this->assertDatabaseHas('worker_devices', ['installation_id' => 'install-2', 'expo_push_token' => self::PUSH_TOKEN]);
    }

    public function test_the_worker_changes_the_nip(): void
    {
        $this->account();
        $token = $this->login()->json('token');

        $this->asWorker($token)
            ->putJson('/api/worker/nip', ['current_nip' => '000001', 'new_nip' => '730418'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_nip']);

        $this->asWorker($token)
            ->putJson('/api/worker/nip', ['current_nip' => self::NIP, 'new_nip' => '654321'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_nip']);

        $this->asWorker($token)
            ->putJson('/api/worker/nip', ['current_nip' => self::NIP, 'new_nip' => '730418'])
            ->assertOk();

        $this->login(['nip' => '730418'])->assertOk();
    }

    public function test_the_session_ends_when_the_worker_is_deactivated_in_the_time_clock(): void
    {
        $account = $this->account();
        $token = $this->login()->json('token');

        $account->employee->timeClockUser->update(['is_active' => false]);

        $this->asWorker($token)->getJson('/api/worker/me')->assertUnauthorized();
    }

    public function test_terminating_the_worker_deletes_the_app_account_and_its_sessions(): void
    {
        $account = $this->account();
        $token = $this->login()->json('token');

        app(EmployeeTerminationService::class)->terminate($account->employee, 'Renuncia voluntaria', null);

        $this->assertSame(0, $account->tokens()->count());
        $this->assertSame(0, $account->devices()->count());
        $this->assertModelMissing($account);
        $this->asWorker($token)->getJson('/api/worker/me')->assertUnauthorized();
    }

    public function test_a_worker_session_cannot_reach_the_panel(): void
    {
        $account = $this->account();
        $token = $this->login()->json('token');

        $this->asWorker($token)->getJson('/api/me')->assertUnauthorized();
        $this->asWorker($token)->getJson("/api/employees/{$account->employee_id}")->assertUnauthorized();
    }

    public function test_a_panel_session_cannot_reach_the_worker_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/worker/me')->assertUnauthorized();
    }
}
