<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\TimeClockUser;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Database\Seeders\VacationEntitlementSeeder;
use Tests\TestCase;

class SelfServiceVacationTest extends TestCase
{
    /** En producción el PIN del checador es el RFC. */
    private const PIN = 'GOMJ900512AB1';

    private const BIRTHDATE = '1990-05-12';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(VacationEntitlementSeeder::class);
        CarbonImmutable::setTestNow('2026-09-16');
        config(['vacations.start_date' => null]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Empleado de lunes a viernes con usuario en el checador. */
    private function employee(bool $activeInTimeClock = true): Employee
    {
        $employee = Employee::factory()->create([
            'birthdate' => self::BIRTHDATE,
            'hire_date' => now()->subYears(3)->toDateString(),
        ]);

        $shift = Shift::create(['name' => 'Oficina '.$employee->id, 'tolerance_minutes' => 10]);
        $shift->syncDays(collect(range(1, 5))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->all());

        $employee->shiftAssignments()->create([
            'shift_id' => $shift->id,
            'starts_on' => $employee->hire_date,
        ]);

        TimeClockUser::create([
            'pin' => self::PIN,
            'name' => $employee->name,
            'employee_id' => $employee->id,
            'is_active' => $activeInTimeClock,
        ]);

        return $employee;
    }

    /** @return array<string, string> */
    private function identity(array $overrides = []): array
    {
        return ['pin' => self::PIN, 'birthdate' => self::BIRTHDATE, ...$overrides];
    }

    public function test_the_worker_sees_their_balance_without_a_session(): void
    {
        $this->employee();

        $this->postJson('/api/self-service/vacations/lookup', $this->identity())
            ->assertOk()
            ->assertJsonPath('data.balance.available_days', 16)
            ->assertJsonPath('data.balance.years_of_service', 3)
            ->assertJsonPath('data.earliest_start_on', '2026-10-16')
            ->assertJsonPath('data.requests', []);
    }

    public function test_the_rfc_is_accepted_in_lowercase_and_with_spaces(): void
    {
        $this->employee();

        $this->postJson('/api/self-service/vacations/lookup', $this->identity(['pin' => ' gomj 900512 ab1 ']))
            ->assertOk();
    }

    public function test_a_wrong_birthdate_does_not_reveal_the_employee(): void
    {
        $this->employee();

        $this->postJson('/api/self-service/vacations/lookup', $this->identity(['birthdate' => '1991-05-12']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pin'])
            ->assertJsonMissingPath('data');
    }

    public function test_an_inactive_time_clock_user_cannot_identify(): void
    {
        $this->employee(activeInTimeClock: false);

        $this->postJson('/api/self-service/vacations/lookup', $this->identity())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_repeated_failures_lock_the_number(): void
    {
        $this->employee();

        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/self-service/vacations/lookup', $this->identity(['birthdate' => '2000-01-01']));
        }

        // Ni con la fecha correcta: el número quedó bloqueado.
        $this->postJson('/api/self-service/vacations/lookup', $this->identity())
            ->assertUnprocessable()
            ->assertJsonPath('errors.pin.0', fn (string $message) => str_contains($message, 'Demasiados intentos'));
    }

    public function test_the_worker_requests_vacations_a_month_ahead(): void
    {
        $employee = $this->employee();

        $this->postJson('/api/self-service/vacations/requests', $this->identity([
            'starts_on' => '2026-10-19',
            'ends_on' => '2026-10-23',
            'comments' => 'Viaje familiar',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.balance.available_days', 11)
            ->assertJsonPath('data.requests.0.status', VacationRequest::PENDING)
            ->assertJsonPath('data.requests.0.requested_days', 5);

        $this->assertDatabaseHas('vacation_requests', [
            'employee_id' => $employee->id,
            'requested_by' => null,
            'comments' => 'Viaje familiar',
        ]);
    }

    public function test_the_worker_cannot_request_with_less_than_a_month_notice(): void
    {
        $this->employee();

        $this->postJson('/api/self-service/vacations/requests', $this->identity([
            'starts_on' => '2026-10-15',
            'ends_on' => '2026-10-20',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_the_preview_applies_the_same_notice_rule(): void
    {
        $this->employee();

        $this->postJson('/api/self-service/vacations/preview', $this->identity([
            'starts_on' => '2026-10-19',
            'ends_on' => '2026-10-23',
        ]))
            ->assertOk()
            ->assertJsonPath('data.working_days', 5)
            ->assertJsonPath('data.has_enough_balance', true);

        $this->postJson('/api/self-service/vacations/preview', $this->identity([
            'starts_on' => '2026-09-21',
            'ends_on' => '2026-09-25',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);
    }
}
