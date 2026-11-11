<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\SubDepartment;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\VacationPeriodService;
use App\Services\VacationRequestService;
use App\Services\VacationSheetService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\CarbonImmutable;
use Database\Seeders\VacationEntitlementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VacationRequestSheetTest extends TestCase
{
    use RefreshDatabase;

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

    /** Empleado de lunes a viernes en Contabilidad, con dos años de antigüedad. */
    private function employee(): Employee
    {
        $department = Department::create(['name' => 'Contabilidad', 'code' => 'CON']);
        $billing = SubDepartment::create(['department_id' => $department->id, 'name' => 'Facturación']);

        $employee = Employee::factory()->create([
            'hire_date' => '2024-09-16',
            'sub_department_id' => $billing->id,
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

    private function request(Employee $employee, string $from = '2026-11-09', string $to = '2026-11-13'): VacationRequest
    {
        return app(VacationRequestService::class)->create(
            $employee,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            null,
            null,
        );
    }

    /**
     * wkhtmltopdf no corre en las pruebas: se intercepta la fachada y se
     * revisa qué plantilla y qué datos recibe.
     *
     * @param  callable(string, array<string, mixed>): bool  $expectation
     */
    private function expectPdf(callable $expectation): void
    {
        SnappyPdf::shouldReceive('loadView')->once()->withArgs($expectation)->andReturnSelf();
        SnappyPdf::shouldReceive('setPaper')->once()->with('letter')->andReturnSelf();
        SnappyPdf::shouldReceive('setOptions')->once()->andReturnSelf();
        SnappyPdf::shouldReceive('download')
            ->once()
            ->andReturnUsing(fn (string $filename) => response('%PDF', 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]));
    }

    public function test_the_sheet_brings_the_employee_data_and_the_balance(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();
        $request = $this->request($employee);

        // Año 2 en curso: 14 días, menos los 5 de esta solicitud.
        $this->getJson("/api/vacation-requests/{$request->id}/sheet")
            ->assertOk()
            ->assertJsonPath('data.request.id', $request->id)
            ->assertJsonPath('data.request.requested_days', 5)
            ->assertJsonPath('data.employee.full_name', $employee->full_name)
            ->assertJsonPath('data.employee.hire_date', '2024-09-16')
            ->assertJsonPath('data.employee.department', 'Contabilidad')
            ->assertJsonPath('data.period.year_number', 2)
            ->assertJsonPath('data.entitled_days', 14)
            ->assertJsonPath('data.available_days', 9);
    }

    public function test_the_sheet_requires_the_view_permission(): void
    {
        $this->actingAsUserWith('vacaciones.solicitar');
        $request = $this->request($this->employee());

        $this->getJson("/api/vacation-requests/{$request->id}/sheet")->assertForbidden();
    }

    public function test_an_employee_without_periods_shows_the_days_to_come(): void
    {
        $this->actingAsUserWith('vacaciones.ver');

        $employee = Employee::factory()->create(['hire_date' => '2026-03-01']);
        $request = VacationRequest::create([
            'employee_id' => $employee->id,
            'starts_on' => '2027-04-05',
            'ends_on' => '2027-04-09',
            'requested_days' => 5,
        ]);

        $this->getJson("/api/vacation-requests/{$request->id}/sheet")
            ->assertOk()
            ->assertJsonPath('data.period', null)
            ->assertJsonPath('data.entitled_days', 12);
    }

    public function test_the_sheet_pdf_renders_the_request_template(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();
        $request = $this->request($employee);

        $this->expectPdf(fn (string $view, array $data) => $view === 'pdf.vacation-sheet'
            && $data['request']->is($request)
            && $data['employee']['full_name'] === $employee->full_name);

        $this->get("/api/vacation-requests/{$request->id}/sheet/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "attachment; filename=\"ficha-vacaciones-{$request->id}.pdf\"");
    }

    public function test_the_period_summary_lists_only_approved_requests(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();
        $service = app(VacationRequestService::class);

        $approved = $this->request($employee, '2026-10-05', '2026-10-07');
        $service->approve($approved, null);

        $pending = $this->request($employee, '2026-11-09', '2026-11-10');

        $rejected = $this->request($employee, '2026-12-07', '2026-12-08');
        $service->reject($rejected, null, 'Cobertura');

        $period = $employee->vacationPeriods()->where('year_number', 2)->firstOrFail();
        $summary = app(VacationSheetService::class)->periodSummary($period);

        $this->assertCount(1, $summary['rows']);
        $this->assertTrue($summary['rows'][0]['request']->is($approved));
        $this->assertSame(3.0, $summary['rows'][0]['days']);
        $this->assertSame(3.0, $summary['approved_days']);
        $this->assertSame(2.0, $summary['pending_days']);
        $this->assertNotNull($pending->fresh());
    }

    public function test_the_period_summary_pdf_renders_the_period_template(): void
    {
        $this->actingAsUserWith('vacaciones.ver');
        $employee = $this->employee();
        app(VacationRequestService::class)->approve($this->request($employee), null);

        $period = $employee->vacationPeriods()->where('year_number', 2)->firstOrFail();

        $this->expectPdf(fn (string $view, array $data) => $view === 'pdf.vacation-period-summary'
            && $data['period']->is($period)
            && $data['rows']->count() === 1);

        $this->get("/api/vacation-periods/{$period->id}/summary/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /** Las plantillas se compilan de verdad: un error de Blade saldría aquí y no en producción. */
    public function test_the_templates_render_in_spanish(): void
    {
        $employee = $this->employee();
        $request = $this->request($employee);
        app(VacationRequestService::class)->approve($request, null);

        $sheets = app(VacationSheetService::class);
        $period = $employee->vacationPeriods()->where('year_number', 2)->firstOrFail();

        $sheet = view('pdf.vacation-sheet', $sheets->sheet($request))->render();
        $this->assertStringContainsString($employee->full_name, $sheet);
        $this->assertStringContainsString('Del 9 de noviembre de 2026 al 13 de noviembre de 2026', $sheet);
        $this->assertStringContainsString('Firma del jefe inmediato', $sheet);

        $summary = view('pdf.vacation-period-summary', $sheets->periodSummary($period))->render();
        $this->assertStringContainsString('Año 2 de servicio', $summary);
        $this->assertStringContainsString('Total aprobado', $summary);
        $this->assertStringContainsString('Sin pago registrado', $summary);
        $this->assertStringNotContainsString('Firma del', $summary);
    }

    public function test_the_sheet_shows_the_premium_once_paid(): void
    {
        $employee = $this->employee();
        $request = $this->request($employee);
        $sheets = app(VacationSheetService::class);

        $blank = view('pdf.vacation-sheet', $sheets->sheet($request))->render();
        $this->assertStringNotContainsString('$1,850.50', $blank);

        $employee->vacationPeriods()->where('year_number', 2)->firstOrFail()
            ->recordPremiumPayment(1850.5, CarbonImmutable::parse('2026-09-10'), null, 25);

        $paid = view('pdf.vacation-sheet', $sheets->sheet($request->fresh()))->render();
        $this->assertStringContainsString('$1,850.50 (25%) el 10/09/2026', $paid);
    }

    public function test_the_period_summary_shows_the_premium_payment(): void
    {
        $employee = $this->employee();
        $period = app(VacationPeriodService::class)->ensurePeriods($employee)
            ->firstWhere('year_number', 2);
        $period->recordPremiumPayment(1850.5, CarbonImmutable::parse('2026-09-10'), null, 25);

        $summary = view('pdf.vacation-period-summary', app(VacationSheetService::class)->periodSummary($period->fresh()))->render();

        $this->assertStringContainsString('10/09/2026', $summary);
        $this->assertStringContainsString('$1,850.50', $summary);
        $this->assertStringContainsString('25%', $summary);
    }
}
