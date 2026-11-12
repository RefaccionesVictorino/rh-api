<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\TimeClockUser;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\WorkerAccount;
use Database\Seeders\VacationEntitlementSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerSelfServiceDataTest extends TestCase
{
    private const RFC = 'GOMJ900512AB1';

    private const BIRTHDATE = '1990-05-12';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(VacationEntitlementSeeder::class);
        config(['vacations.start_date' => null]);
        $this->travelTo('2026-09-16 12:00:00');

        $this->employee = Employee::factory()->create([
            'rfc' => self::RFC,
            'birthdate' => self::BIRTHDATE,
            'hire_date' => '2023-09-16',
        ]);

        $shift = Shift::create(['name' => 'Oficina', 'tolerance_minutes' => 10]);
        $shift->syncDays(collect(range(1, 5))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->all());
        $this->employee->shiftAssignments()->create(['shift_id' => $shift->id, 'starts_on' => '2023-09-16']);

        TimeClockUser::create(['pin' => self::RFC, 'name' => $this->employee->name, 'employee_id' => $this->employee->id]);

        $account = WorkerAccount::create([
            'employee_id' => $this->employee->id,
            'nip' => '482915',
            'activated_at' => now(),
        ]);

        Sanctum::actingAs($account, ['worker']);
    }

    public function test_the_worker_sees_the_month_of_punches(): void
    {
        AttendancePunch::create([
            'employee_id' => $this->employee->id,
            'pin' => self::RFC,
            'punched_at' => '2026-09-10 09:05:00',
            'punch_type' => AttendancePunch::TYPE_IN,
            'source' => 'device',
        ]);
        AttendancePunch::create([
            'employee_id' => $this->employee->id,
            'pin' => self::RFC,
            'punched_at' => '2026-09-10 18:02:00',
            'punch_type' => AttendancePunch::TYPE_OUT,
            'source' => AttendancePunch::SOURCE_MANUAL,
            'created_by' => User::factory()->create(['name' => 'Ana de RH'])->id,
            'notes' => 'Nota interna de RH',
        ]);

        $response = $this->getJson('/api/worker/attendance?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-09-01')
            ->assertJsonPath('data.to', '2026-09-30')
            ->assertJsonCount(30, 'data.days')
            ->assertJsonStructure(['data' => ['today', 'summary' => ['on_time', 'late', 'absent', 'attendance_rate']]]);

        $day = collect($response->json('data.days'))->firstWhere('date', '2026-09-10');

        $this->assertSame('on_time', $day['status']);
        $this->assertSame(['09:05', '18:02'], [$day['first_in'], $day['last_out']]);
        $this->assertCount(2, $day['punches']);
        $this->assertArrayNotHasKey('notes', $day['punches'][1]);
        $this->assertArrayNotHasKey('created_by', $day['punches'][1]);
        $this->assertStringNotContainsString('Ana de RH', $response->getContent());
    }

    public function test_without_a_month_it_shows_the_current_one(): void
    {
        $this->getJson('/api/worker/attendance')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-09-01')
            ->assertJsonPath('data.today', '2026-09-16');
    }

    public function test_an_invalid_month_is_rejected(): void
    {
        $this->getJson('/api/worker/attendance?month=septiembre')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_the_worker_requests_vacations_a_month_ahead(): void
    {
        $this->postJson('/api/worker/vacations/requests', [
            'starts_on' => '2026-10-19',
            'ends_on' => '2026-10-23',
            'comments' => 'Viaje familiar',
        ])
            ->assertCreated()
            ->assertJsonPath('data.balance.available_days', 11)
            ->assertJsonPath('data.requests.0.status', VacationRequest::PENDING)
            ->assertJsonPath('data.requests.0.requested_days', 5);

        $this->assertDatabaseHas('vacation_requests', [
            'employee_id' => $this->employee->id,
            'requested_by' => null,
            'comments' => 'Viaje familiar',
        ]);
    }

    public function test_the_worker_cannot_request_with_less_than_a_month_notice(): void
    {
        $this->postJson('/api/worker/vacations/requests', ['starts_on' => '2026-10-15', 'ends_on' => '2026-10-20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on' => 'un mes de anticipación']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_the_preview_counts_working_days_and_applies_the_same_notice_rule(): void
    {
        // Las vacaciones cuentan de lunes a sábado; el domingo no descuenta.
        $this->postJson('/api/worker/vacations/preview', ['starts_on' => '2026-10-19', 'ends_on' => '2026-10-25'])
            ->assertOk()
            ->assertJsonPath('data.working_days', 6)
            ->assertJsonPath('data.calendar_days', 7)
            ->assertJsonPath('data.has_enough_balance', true);

        $this->postJson('/api/worker/vacations/preview', ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_the_balance_limits_the_request(): void
    {
        // Casi cuatro semanas de lunes a sábado rebasan los 16 días del año 3.
        $this->postJson('/api/worker/vacations/preview', ['starts_on' => '2026-10-19', 'ends_on' => '2026-11-13'])
            ->assertOk()
            ->assertJsonPath('data.has_enough_balance', false);

        $this->postJson('/api/worker/vacations/requests', ['starts_on' => '2026-10-19', 'ends_on' => '2026-11-13'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on' => 'Saldo insuficiente']);
    }

    public function test_a_request_cannot_overlap_another_one(): void
    {
        $this->postJson('/api/worker/vacations/requests', ['starts_on' => '2026-10-19', 'ends_on' => '2026-10-23'])
            ->assertCreated();

        $this->postJson('/api/worker/vacations/requests', ['starts_on' => '2026-10-22', 'ends_on' => '2026-10-27'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on' => 'traslapa']);
    }

    public function test_the_range_and_the_comments_have_limits(): void
    {
        $this->postJson('/api/worker/vacations/requests', ['starts_on' => '2026-10-23', 'ends_on' => '2026-10-19'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on']);

        $this->postJson('/api/worker/vacations/requests', ['starts_on' => '2026-10-19', 'ends_on' => '2027-03-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on' => '120 días']);

        $this->postJson('/api/worker/vacations/requests', [
            'starts_on' => '2026-10-19',
            'ends_on' => '2026-10-23',
            'comments' => str_repeat('a', 501),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comments']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_the_worker_sees_the_same_vacation_summary_as_the_web_portal(): void
    {
        $app = $this->getJson('/api/worker/vacations')
            ->assertOk()
            ->assertJsonPath('data.employee.full_name', $this->employee->full_name)
            ->assertJsonPath('data.balance.years_of_service', 3)
            ->assertJsonPath('data.requests', [])
            ->json('data');

        $portal = $this->postJson('/api/self-service/vacations/lookup', [
            'pin' => self::RFC,
            'birthdate' => self::BIRTHDATE,
        ])->assertOk()->json('data');

        $this->assertSame($portal, $app);
    }
}
