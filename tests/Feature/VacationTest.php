<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use App\Models\VacationEntitlement;
use App\Models\VacationRequest;
use App\Services\VacationPeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\VacationEntitlementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VacationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(VacationEntitlementSeeder::class);
        CarbonImmutable::setTestNow('2026-09-16');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

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

    /** Empleado de lunes a viernes, contratado hace los años indicados. */
    private function employee(int $yearsAgo = 3): Employee
    {
        $employee = Employee::factory()->create([
            'hire_date' => now()->subYears($yearsAgo)->toDateString(),
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

        return $employee;
    }

    public function test_the_entitlement_table_follows_the_2023_reform(): void
    {
        $expected = [1 => 12, 2 => 14, 3 => 16, 4 => 18, 5 => 20, 6 => 22, 10 => 22, 11 => 24, 16 => 26];

        foreach ($expected as $yearNumber => $days) {
            $this->assertSame($days, VacationEntitlement::daysForYear($yearNumber), "Año {$yearNumber}");
        }
    }

    public function test_the_table_covers_any_seniority(): void
    {
        $this->assertGreaterThan(0, VacationEntitlement::daysForYear(80));
    }

    public function test_periods_are_generated_for_each_anniversary(): void
    {
        $employee = $this->employee(yearsAgo: 3);

        $periods = app(VacationPeriodService::class)->ensurePeriods($employee);

        $this->assertCount(3, $periods);
        $this->assertSame([12, 14, 16], $periods->pluck('entitled_days')->all());
        $this->assertSame([1, 2, 3], $periods->pluck('year_number')->all());
    }

    public function test_an_employee_without_a_full_year_has_no_periods(): void
    {
        $employee = $this->employee(yearsAgo: 0);

        $this->assertCount(0, app(VacationPeriodService::class)->ensurePeriods($employee));
    }

    public function test_a_period_expires_six_months_after_the_service_year(): void
    {
        $employee = Employee::factory()->create(['hire_date' => '2024-03-01']);

        $period = app(VacationPeriodService::class)->ensurePeriods($employee)->first();

        $this->assertSame('2025-03-01', $period->starts_on->toDateString());
        $this->assertSame('2026-02-28', $period->ends_on->toDateString());
        $this->assertSame('2026-08-31', $period->expires_on->toDateString());
    }

    public function test_correcting_the_hire_date_realigns_the_periods(): void
    {
        $service = app(VacationPeriodService::class);
        $employee = Employee::factory()->create(['hire_date' => '2019-03-18']);
        $service->ensurePeriods($employee);

        $employee->update(['hire_date' => '2014-03-18']);
        $periods = $service->ensurePeriods($employee->fresh());

        $this->assertCount(12, $periods);
        $this->assertSame('2015-03-18', $periods->first()->starts_on->toDateString());
        $this->assertSame('2026-03-18', $periods->last()->starts_on->toDateString());
        $this->assertSame(
            $periods->pluck('starts_on')->unique()->count(),
            $periods->count(),
            'Cada periodo debe cubrir un aniversario distinto.',
        );
    }

    public function test_realigning_stops_two_copies_of_a_period_from_inflating_the_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $service = app(VacationPeriodService::class);
        $employee = Employee::factory()->create(['hire_date' => '2019-03-18']);
        $service->ensurePeriods($employee);

        $employee->update(['hire_date' => '2014-03-18']);

        $response = $this->getJson("/api/employees/{$employee->id}/vacation-balance")->assertOk();

        $available = collect($response->json('data.periods'))->where('is_available', true);

        // Dos periodos vigentes a la vez son normales mientras el más viejo no
        // vence; lo que no puede pasar es que cubran el mismo rango de fechas.
        $this->assertSame(
            $available->pluck('starts_on')->unique()->count(),
            $available->count(),
        );
        $this->assertEqualsWithDelta(48, $response->json('data.available_days'), 0.01);
    }

    public function test_an_expired_copy_of_a_period_stops_counting_once_realigned(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $service = app(VacationPeriodService::class);
        $employee = Employee::factory()->create(['hire_date' => '2019-03-18']);
        $service->ensurePeriods($employee);

        // Antes de realinear, el año 7 de la fecha vieja y el 12 de la real
        // cubrían ambos 2026-03-18 y el saldo salía inflado.
        $employee->update(['hire_date' => '2014-03-18']);
        $service->ensurePeriods($employee->fresh());

        CarbonImmutable::setTestNow('2026-09-18');

        $response = $this->getJson("/api/employees/{$employee->id}/vacation-balance")->assertOk();

        $this->assertEqualsWithDelta(24, $response->json('data.available_days'), 0.01);
    }

    public function test_a_shortened_hire_date_drops_the_periods_that_no_longer_exist(): void
    {
        $service = app(VacationPeriodService::class);
        $employee = Employee::factory()->create(['hire_date' => '2014-03-18']);

        $this->assertCount(12, $service->ensurePeriods($employee));

        $employee->update(['hire_date' => '2023-03-18']);

        $this->assertCount(3, $service->ensurePeriods($employee->fresh()));
    }

    public function test_a_period_with_taken_days_survives_the_realignment(): void
    {
        $service = app(VacationPeriodService::class);
        $employee = Employee::factory()->create(['hire_date' => '2014-03-18']);
        $service->ensurePeriods($employee);

        $employee->vacationPeriods()->where('year_number', 12)->update(['taken_days' => 5]);
        $employee->update(['hire_date' => '2023-03-18']);

        $periods = $service->ensurePeriods($employee->fresh());

        $this->assertNotNull($periods->firstWhere('year_number', 12));
        $this->assertSame(5.0, $periods->firstWhere('year_number', 12)->taken_days);
    }

    public function test_the_balance_endpoint_reports_the_available_days(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee(yearsAgo: 2);

        $this->getJson("/api/employees/{$employee->id}/vacation-balance")
            ->assertOk()
            ->assertJsonPath('data.years_of_service', 2)
            ->assertJsonPath('data.next_entitlement_days', 16)
            ->assertJsonCount(2, 'data.periods')
            ->assertJsonPath('data.available_days', 26)
            ->assertJsonPath('data.expired_days', 0);
    }

    public function test_an_expired_period_stops_counting_as_available(): void
    {
        $this->actingAsUserWith('vacaciones.ver');

        // Antigüedad suficiente para que el primer periodo ya haya vencido: se
        // gana al año y dura un año y medio más.
        $employee = $this->employee(yearsAgo: 3);

        $response = $this->getJson("/api/employees/{$employee->id}/vacation-balance")->assertOk();

        $first = collect($response->json('data.periods'))->firstWhere('year_number', 1);

        $this->assertTrue($first['is_expired']);
        $this->assertFalse($first['is_available']);
        // Los 12 días del primer año quedan fuera del saldo disponible.
        $this->assertEqualsWithDelta(12, $response->json('data.expired_days'), 0.01);
        $this->assertEqualsWithDelta(30, $response->json('data.available_days'), 0.01);
    }

    public function test_preview_counts_only_working_days(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();

        // Lunes a domingo: 5 hábiles de 7 naturales.
        $response = $this->postJson("/api/employees/{$employee->id}/vacation-requests/preview", [
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-11',
        ])->assertOk();

        $this->assertSame(5, $response->json('data.working_days'));
        $this->assertSame(7, $response->json('data.calendar_days'));
        $this->assertTrue($response->json('data.has_enough_balance'));
    }

    public function test_a_holiday_inside_the_range_does_not_consume_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();

        Holiday::create([
            'date' => '2026-10-07',
            'name' => 'Festivo de prueba',
            'observance' => Holiday::REST,
        ]);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests/preview", [
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-09',
        ])->assertOk()->assertJsonPath('data.working_days', 4);
    }

    public function test_creating_a_request_consumes_the_oldest_period_first(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-09',
        ])->assertCreated()
            ->assertJsonPath('data.requested_days', 5)
            ->assertJsonPath('data.status', VacationRequest::PENDING)
            ->assertJsonCount(5, 'data.dates');

        $period = $employee->vacationPeriods()->first();

        $this->assertSame(5.0, $period->fresh()->taken_days);
        $this->assertSame(7.0, $period->fresh()->remaining_days);
        $this->assertDatabaseCount('vacation_request_days', 5);
    }

    public function test_a_pending_request_already_holds_the_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09',
        ])->assertCreated();

        $this->getJson("/api/employees/{$employee->id}/vacation-balance")
            ->assertOk()
            ->assertJsonPath('data.available_days', 7);
    }

    public function test_rejects_a_request_without_enough_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        // 12 días de saldo contra 15 hábiles en tres semanas.
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-23',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_rejects_overlapping_requests(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-07',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-07', 'ends_on' => '2026-10-09',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);
    }

    public function test_rejects_a_range_without_working_days(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        // Sábado y domingo.
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-10', 'ends_on' => '2026-10-11',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);
    }

    public function test_rejecting_a_request_returns_the_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.autorizar');
        $employee = $this->employee(yearsAgo: 1);

        $id = $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09',
        ])->json('data.id');

        $this->postJson("/api/vacation-requests/{$id}/reject", [
            'rejection_reason' => 'Temporada alta',
        ])->assertOk()
            ->assertJsonPath('data.status', VacationRequest::REJECTED);

        $this->assertSame(0.0, $employee->vacationPeriods()->first()->fresh()->taken_days);
    }

    public function test_approving_keeps_the_balance_consumed(): void
    {
        $user = $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.autorizar');
        $employee = $this->employee(yearsAgo: 1);

        $id = $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09',
        ])->json('data.id');

        $this->postJson("/api/vacation-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', VacationRequest::APPROVED)
            ->assertJsonPath('data.reviewed_by.id', $user->id);

        $this->assertSame(5.0, $employee->vacationPeriods()->first()->fresh()->taken_days);
    }

    public function test_cannot_approve_twice(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.autorizar');
        $employee = $this->employee(yearsAgo: 1);

        $id = $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09',
        ])->json('data.id');

        $this->postJson("/api/vacation-requests/{$id}/approve")->assertOk();
        $this->postJson("/api/vacation-requests/{$id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_cancelling_an_approved_request_returns_the_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.autorizar');
        $employee = $this->employee(yearsAgo: 1);

        $id = $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09',
        ])->json('data.id');

        $this->postJson("/api/vacation-requests/{$id}/approve")->assertOk();
        $this->postJson("/api/vacation-requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', VacationRequest::CANCELLED);

        $this->assertSame(0.0, $employee->vacationPeriods()->first()->fresh()->taken_days);
    }

    public function test_the_inbox_is_sorted_by_request_date_and_exposes_it(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        // La primera solicitada es la que empieza más tarde, para que el orden
        // por fecha de solicitud no coincida con el de fecha de inicio.
        CarbonImmutable::setTestNow('2026-09-16 09:00:00');
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        ])->assertCreated();

        CarbonImmutable::setTestNow('2026-09-17 15:30:00');
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-07',
        ])->assertCreated();

        $response = $this->getJson('/api/vacations/requests')->assertOk();

        $this->assertSame('2026-10-05', $response->json('data.0.starts_on'));
        $this->assertSame('2026-12-07', $response->json('data.1.starts_on'));
        $this->assertNotNull($response->json('data.0.requested_at'));

        $this->getJson('/api/vacations/requests?sort_by=starts_on&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-10-05');

        $this->getJson("/api/employees/{$employee->id}/vacation-requests")
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-10-05');
    }

    public function test_a_manual_adjustment_changes_the_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.configurar');
        $employee = $this->employee(yearsAgo: 1);

        $period = app(VacationPeriodService::class)->ensurePeriods($employee)->first();

        $this->putJson("/api/vacation-periods/{$period->id}/adjust", [
            'adjustment_days' => 3,
            'adjustment_reason' => 'Días por acuerdo',
        ])->assertOk()
            ->assertJsonPath('data.granted_days', 15)
            ->assertJsonPath('data.remaining_days', 15);
    }

    public function test_the_entitlement_table_can_be_replaced(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.configurar');

        $this->putJson('/api/vacations/entitlements', [
            'entitlements' => [
                ['from_year' => 1, 'to_year' => 1, 'days' => 15],
                ['from_year' => 2, 'to_year' => null, 'days' => 20],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(15, VacationEntitlement::daysForYear(1));
        $this->assertSame(20, VacationEntitlement::daysForYear(9));
    }

    public function test_only_the_last_entitlement_row_can_be_open(): void
    {
        $this->actingAsUserWith('vacaciones.configurar');

        $this->putJson('/api/vacations/entitlements', [
            'entitlements' => [
                ['from_year' => 1, 'to_year' => null, 'days' => 12],
                ['from_year' => 2, 'to_year' => 2, 'days' => 14],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['entitlements.0.to_year']);
    }

    public function test_the_requests_inbox_filters_by_status(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.autorizar');
        $employee = $this->employee(yearsAgo: 2);

        $id = $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-07',
        ])->json('data.id');

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertCreated();

        $this->postJson("/api/vacation-requests/{$id}/approve")->assertOk();

        $this->getJson('/api/vacations/requests')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/vacations/requests?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_on', '2026-11-09');
    }

    public function test_the_requests_inbox_sorts_by_the_requested_column(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-07',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertCreated();

        $this->getJson('/api/vacations/requests?sort_by=starts_on&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-10-05');

        $this->getJson('/api/vacations/requests?sort_by=starts_on&sort_dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-11-09');
    }

    public function test_the_requests_inbox_rejects_an_unknown_sort_column(): void
    {
        $this->actingAsUserWith('vacaciones.ver');

        $this->getJson('/api/vacations/requests?sort_by=comments')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_by']);
    }

    public function test_requires_permission(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee(yearsAgo: 1);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09',
        ])->assertForbidden();
    }
}
