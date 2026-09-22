<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\SubDepartment;
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

        // Cada prueba declara su corte; el del entorno no debe filtrarse.
        config(['vacations.start_date' => null]);
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

    /**
     * El artículo 76 reformado se detiene en 32 días desde el año 31: no sigue
     * subiendo de cinco en cinco. Extrapolarlo regalaría días que la ley no da.
     */
    public function test_the_table_stops_at_the_legal_cap(): void
    {
        $this->assertSame(30, VacationEntitlement::daysForYear(30));
        $this->assertSame(32, VacationEntitlement::daysForYear(31));

        foreach ([35, 40, 50, 80] as $yearNumber) {
            $this->assertSame(
                32,
                VacationEntitlement::daysForYear($yearNumber),
                "Año {$yearNumber} excede el tope legal",
            );
        }
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

    /**
     * El módulo arrancó sin el histórico de lo gozado: los periodos cerrados
     * antes del corte no se generan, para no inventar un pasivo sin comprobar.
     */
    public function test_periods_closed_before_the_start_date_are_not_generated(): void
    {
        config(['vacations.start_date' => '2026-09-16']);

        $employee = $this->employee(yearsAgo: 10);
        $periods = app(VacationPeriodService::class)->ensurePeriods($employee);

        $this->assertSame([10], $periods->pluck('year_number')->all());
    }

    public function test_the_start_date_keeps_the_seniority_of_the_current_period(): void
    {
        config(['vacations.start_date' => '2026-09-16']);

        $employee = $this->employee(yearsAgo: 10);
        $period = app(VacationPeriodService::class)->ensurePeriods($employee)->first();

        $this->assertSame(10, $period->year_number);
        $this->assertSame(22.0, (float) $period->entitled_days);
    }

    public function test_the_start_date_leaves_no_pending_balance(): void
    {
        config(['vacations.start_date' => '2026-09-16']);

        $employee = $this->employee(yearsAgo: 10);
        $summary = app(VacationPeriodService::class)->summary($employee);

        $this->assertEqualsWithDelta(0, $summary['pending_days'], 0.01);
        $this->assertEqualsWithDelta(0, $summary['expired_days'], 0.01);
        $this->assertEqualsWithDelta(22, $summary['available_days'], 0.01);
    }

    public function test_a_period_before_the_start_date_survives_with_taken_days(): void
    {
        $employee = $this->employee(yearsAgo: 10);
        $service = app(VacationPeriodService::class);
        $service->ensurePeriods($employee);

        $employee->vacationPeriods()->where('year_number', 3)->update(['taken_days' => 5]);

        config(['vacations.start_date' => '2026-09-16']);

        $this->assertSame([3, 10], $service->ensurePeriods($employee->fresh())->pluck('year_number')->all());
    }

    public function test_without_a_start_date_every_period_is_generated(): void
    {
        config(['vacations.start_date' => null]);

        $employee = $this->employee(yearsAgo: 10);

        $this->assertCount(10, app(VacationPeriodService::class)->ensurePeriods($employee));
    }

    /**
     * Artículo 81: se gozan durante el año siguiente al de servicio, más seis
     * meses de gracia. Con la prescripción del 516 encima, el periodo vence 18
     * meses después del aniversario.
     */
    public function test_a_period_expires_eighteen_months_after_the_anniversary(): void
    {
        $employee = Employee::factory()->create(['hire_date' => '2024-03-01']);

        $period = app(VacationPeriodService::class)->ensurePeriods($employee)->first();

        $this->assertSame('2025-03-01', $period->starts_on->toDateString());
        $this->assertSame('2026-02-28', $period->ends_on->toDateString());
        $this->assertSame('2026-08-31', $period->expires_on->toDateString());
    }

    public function test_a_prescribed_leftover_is_reported_as_expired(): void
    {
        $this->actingAsUserWith('vacaciones.ver');

        // Contratado en 2024: el año 1 (mar-2025) cerró con sus 12 días sin
        // gozar y ya pasó su prescripción (ago-2026); en curso va el año 2.
        $employee = $this->employee(yearsAgo: 2);
        $employee->update(['hire_date' => '2024-03-01']);

        $response = $this->getJson("/api/employees/{$employee->id}/vacation-balance")->assertOk();

        $available = collect($response->json('data.periods'))->where('is_available', true);

        $this->assertCount(1, $available);
        $this->assertSame(2, $available->first()['year_number']);
        $this->assertEqualsWithDelta(14, $response->json('data.available_days'), 0.01);
        $this->assertEqualsWithDelta(12, $response->json('data.pending_days'), 0.01);
        $this->assertEqualsWithDelta(12, $response->json('data.expired_days'), 0.01);
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

        // Antes de realinear, el año 7 de la fecha vieja y el 12 de la real
        // cubrían ambos 2026-03-18 y el saldo salía al doble.
        $this->assertCount(1, $available);
        $this->assertSame(12, $available->first()['year_number']);
        $this->assertEqualsWithDelta(24, $response->json('data.available_days'), 0.01);
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

        // Se puede pedir solo lo del año en curso (14). Los 12 del año 1 son
        // saldo pendiente: siguen exigibles (no han prescrito) pero no se
        // solicitan; la empresa decide si los paga.
        $this->getJson("/api/employees/{$employee->id}/vacation-balance")
            ->assertOk()
            ->assertJsonPath('data.years_of_service', 2)
            ->assertJsonPath('data.next_entitlement_days', 16)
            ->assertJsonCount(2, 'data.periods')
            ->assertJsonPath('data.available_days', 14)
            ->assertJsonPath('data.pending_days', 12)
            ->assertJsonPath('data.expired_days', 0);
    }

    public function test_leftovers_from_earlier_years_are_pending_not_available(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee(yearsAgo: 3);

        $response = $this->getJson("/api/employees/{$employee->id}/vacation-balance")->assertOk();
        $periods = collect($response->json('data.periods'))->keyBy('year_number');

        $this->assertTrue($periods[1]['is_pending']);
        $this->assertTrue($periods[1]['is_expired']);
        $this->assertTrue($periods[2]['is_pending']);
        $this->assertFalse($periods[2]['is_expired']);
        $this->assertTrue($periods[3]['is_current']);

        // Disponible: solo el año 3. Pendiente: años 1 y 2; de esos, solo el 1
        // ya prescribió.
        $this->assertEqualsWithDelta(16, $response->json('data.available_days'), 0.01);
        $this->assertEqualsWithDelta(26, $response->json('data.pending_days'), 0.01);
        $this->assertEqualsWithDelta(12, $response->json('data.expired_days'), 0.01);
    }

    public function test_pending_balance_cannot_be_requested(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        // 14 días en curso más 12 pendientes; 15 hábiles solo pasarían si el
        // pendiente contara.
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-25',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    /**
     * Se pueden programar vacaciones para un periodo que todavía no abre: los
     * días se cargan a ese periodo y el saldo de hoy no se toca.
     */
    public function test_a_request_in_a_future_period_is_charged_to_that_period(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');

        // Ingreso 2024-09-16: el año 3 abre en septiembre de 2027 y es el que
        // corre en abril de 2028.
        $employee = $this->employee(yearsAgo: 2);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2028-04-03', 'ends_on' => '2028-04-07',
        ])->assertCreated()
            ->assertJsonPath('data.requested_days', 5);

        $future = $employee->vacationPeriods()->where('year_number', 3)->first();

        $this->assertSame('2027-09-16', $future->starts_on->toDateString());
        $this->assertSame(5.0, $future->taken_days);

        $response = $this->getJson("/api/employees/{$employee->id}/vacation-balance")->assertOk();

        // El periodo futuro se conserva y se distingue, pero no suma al saldo.
        $this->assertCount(3, $response->json('data.periods'));
        $this->assertTrue(collect($response->json('data.periods'))->firstWhere('year_number', 3)['is_future']);
        $this->assertEqualsWithDelta(14, $response->json('data.available_days'), 0.01);
    }

    public function test_a_future_request_is_limited_to_that_periods_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        // El año 3 da 16 días; tres semanas de lunes a sábado son 18.
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2028-04-03', 'ends_on' => '2028-04-22',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    /** Una solicitud que cruza el aniversario se reparte: cada día va al periodo que corre ese día. */
    public function test_a_request_across_the_anniversary_splits_between_periods(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        // Aniversario 2027-09-16 (jueves): lunes a miércoles van al año 2,
        // jueves a sábado al año 3.
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2027-09-13', 'ends_on' => '2027-09-18',
        ])->assertCreated()
            ->assertJsonPath('data.requested_days', 6);

        $this->assertSame(3.0, $employee->vacationPeriods()->where('year_number', 2)->first()->taken_days);
        $this->assertSame(3.0, $employee->vacationPeriods()->where('year_number', 3)->first()->taken_days);
    }

    public function test_preview_counts_only_working_days(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();

        // Lunes a domingo: 6 hábiles de 7 naturales, solo el domingo no cuenta.
        $response = $this->postJson("/api/employees/{$employee->id}/vacation-requests/preview", [
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-15',
        ])->assertOk();

        $this->assertSame(6, $response->json('data.working_days'));
        $this->assertSame(7, $response->json('data.calendar_days'));
        $this->assertTrue($response->json('data.has_enough_balance'));
    }

    /**
     * El saldo se consume de lunes a sábado para todos: el turno define a qué
     * hora se presenta cada quien, no cuántos días de vacaciones gasta.
     */
    public function test_working_days_ignore_the_employee_shift(): void
    {
        $this->actingAsUserWith('vacaciones.ver');

        // Sin turno asignado el rango se cuenta igual que con uno.
        $employee = Employee::factory()->create([
            'hire_date' => now()->subYears(2)->toDateString(),
        ]);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests/preview", [
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-15',
        ])->assertOk()->assertJsonPath('data.working_days', 6);
    }

    public function test_a_holiday_inside_the_range_does_not_consume_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();

        Holiday::create([
            'date' => '2026-11-11',
            'name' => 'Festivo de prueba',
            'observance' => Holiday::REST,
        ]);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests/preview", [
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-13',
        ])->assertOk()->assertJsonPath('data.working_days', 4);
    }

    public function test_creating_a_request_consumes_the_oldest_period_first(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-13',
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
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13',
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
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-27',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_rejects_overlapping_requests(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-11', 'ends_on' => '2026-11-13',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);
    }

    public function test_rejects_a_range_without_working_days(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        // Domingo: el único día de la semana que no consume saldo.
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-15', 'ends_on' => '2026-11-15',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);
    }

    /** El sábado sí consume saldo: la semana laboral va de lunes a sábado. */
    public function test_saturday_consumes_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 1);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-14', 'ends_on' => '2026-11-14',
        ])->assertCreated()
            ->assertJsonPath('data.requested_days', 1);
    }

    public function test_rejecting_a_request_returns_the_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.autorizar');
        $employee = $this->employee(yearsAgo: 1);

        $id = $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13',
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
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13',
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
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13',
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
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13',
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
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertCreated();

        $response = $this->getJson('/api/vacations/requests')->assertOk();

        $this->assertSame('2026-11-09', $response->json('data.0.starts_on'));
        $this->assertSame('2026-12-07', $response->json('data.1.starts_on'));
        $this->assertNotNull($response->json('data.0.requested_at'));

        $this->getJson('/api/vacations/requests?sort_by=starts_on&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-11-09');

        $this->getJson("/api/employees/{$employee->id}/vacation-requests")
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-11-09');
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
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->json('data.id');

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-12-14', 'ends_on' => '2026-12-16',
        ])->assertCreated();

        $this->postJson("/api/vacation-requests/{$id}/approve")->assertOk();

        $this->getJson('/api/vacations/requests')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/vacations/requests?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_on', '2026-12-14');
    }

    public function test_the_requests_inbox_sorts_by_the_requested_column(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertCreated();

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-12-14', 'ends_on' => '2026-12-16',
        ])->assertCreated();

        $this->getJson('/api/vacations/requests?sort_by=starts_on&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-11-09');

        $this->getJson('/api/vacations/requests?sort_by=starts_on&sort_dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', '2026-12-14');
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
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13',
        ])->assertForbidden();
    }

    public function test_a_request_needs_a_month_of_notice(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        // Hoy es 2026-09-16: el 15 de octubre queda un día corto.
        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-15', 'ends_on' => '2026-10-16',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_a_request_exactly_a_month_ahead_is_accepted(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');
        $employee = $this->employee(yearsAgo: 2);

        $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
            'starts_on' => '2026-10-16', 'ends_on' => '2026-10-17',
        ])->assertCreated();
    }

    /**
     * Las sub áreas hermanas cuentan: en el organigrama casi ninguna tiene
     * padre, y la cobertura se acomoda entre las del mismo departamento.
     */
    public function test_branch_overlaps_cover_the_whole_department(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');

        $department = Department::create(['name' => 'Contabilidad', 'code' => 'CON']);
        $billing = SubDepartment::create(['department_id' => $department->id, 'name' => 'Facturación']);
        $receivable = SubDepartment::create(['department_id' => $department->id, 'name' => 'Cuentas por Cobrar']);

        $applicant = $this->employee(yearsAgo: 2);
        $applicant->update(['sub_department_id' => $billing->id]);

        $peer = $this->employee(yearsAgo: 2);
        $peer->update(['sub_department_id' => $billing->id]);

        $sibling = $this->employee(yearsAgo: 2);
        $sibling->update(['sub_department_id' => $receivable->id]);

        foreach ([$peer, $sibling] as $employee) {
            $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
                'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
            ])->assertCreated();
        }

        $this->postJson("/api/employees/{$applicant->id}/vacation-requests/branch-overlaps", [
            'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12',
        ])->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_branch_overlaps_ignore_other_departments_and_the_applicant(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');

        $department = Department::create(['name' => 'Contabilidad', 'code' => 'CON']);
        $other = Department::create(['name' => 'Almacén', 'code' => 'ALM']);

        $billing = SubDepartment::create(['department_id' => $department->id, 'name' => 'Facturación']);
        $shipping = SubDepartment::create(['department_id' => $other->id, 'name' => 'Embarques']);

        $applicant = $this->employee(yearsAgo: 2);
        $applicant->update(['sub_department_id' => $billing->id]);

        $outsider = $this->employee(yearsAgo: 2);
        $outsider->update(['sub_department_id' => $shipping->id]);

        foreach ([$applicant, $outsider] as $employee) {
            $this->postJson("/api/employees/{$employee->id}/vacation-requests", [
                'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
            ])->assertCreated();
        }

        $this->postJson("/api/employees/{$applicant->id}/vacation-requests/branch-overlaps", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_branch_overlaps_leave_out_dates_that_do_not_cross(): void
    {
        $this->actingAsUserWith('vacaciones.ver', 'vacaciones.solicitar');

        $department = Department::create(['name' => 'Contabilidad', 'code' => 'CON']);
        $billing = SubDepartment::create(['department_id' => $department->id, 'name' => 'Facturación']);

        $applicant = $this->employee(yearsAgo: 2);
        $applicant->update(['sub_department_id' => $billing->id]);

        $peer = $this->employee(yearsAgo: 2);
        $peer->update(['sub_department_id' => $billing->id]);

        $this->postJson("/api/employees/{$peer->id}/vacation-requests", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertCreated();

        $this->postJson("/api/employees/{$applicant->id}/vacation-requests/branch-overlaps", [
            'starts_on' => '2026-11-12', 'ends_on' => '2026-11-14',
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_branch_overlaps_are_empty_without_an_area(): void
    {
        $this->actingAsUserWith('vacaciones.ver');

        $applicant = $this->employee(yearsAgo: 2);
        $applicant->update(['sub_department_id' => null]);

        $this->postJson("/api/employees/{$applicant->id}/vacation-requests/branch-overlaps", [
            'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11',
        ])->assertOk()->assertJsonCount(0, 'data');
    }
}
