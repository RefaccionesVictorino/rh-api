<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\AttendanceCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AbsenceTest extends TestCase
{
    use RefreshDatabase;

    private const LOCATION = 'https://cdn.example.net/empleados/1/ausencias/abc-certificado.pdf';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.file_upload', [
            'url' => 'https://upload.example.net/uploadObject',
            'bucket' => 'bucket-de-prueba',
            'project_key' => 'proyecto-de-prueba',
        ]);
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

    /** Empleado de lunes a viernes de 09:00 a 18:00. */
    private function employeeWithShift(): Employee
    {
        $employee = Employee::factory()->create(['hire_date' => '2024-01-15']);

        $shift = Shift::create(['name' => 'Oficina '.$employee->id, 'tolerance_minutes' => 10]);
        $shift->syncDays(collect(range(1, 5))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->all());

        $employee->shiftAssignments()->create([
            'shift_id' => $shift->id,
            'starts_on' => '2024-01-15',
        ]);

        return $employee;
    }

    public function test_registers_an_incapacity(): void
    {
        $user = $this->actingAsUserWith('ausencias.registrar');
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/absences", [
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-09',
            'folio' => 'AB123456',
            'issued_on' => '2026-09-07',
        ])->assertCreated()
            ->assertJsonPath('data.type_label', 'Incapacidad por enfermedad general')
            ->assertJsonPath('data.is_incapacity', true)
            ->assertJsonPath('data.calendar_days', 3)
            ->assertJsonPath('data.folio', 'AB123456')
            ->assertJsonPath('data.document_url', null)
            ->assertJsonPath('data.created_by.id', $user->id);
    }

    public function test_uploads_the_certificate_and_stores_the_returned_location(): void
    {
        $this->actingAsUserWith('ausencias.registrar');

        Http::fake([
            'upload.example.net/*' => Http::response(['location' => self::LOCATION]),
        ]);

        $employee = Employee::factory()->create();

        $this->post("/api/employees/{$employee->id}/absences", [
            'type' => Absence::SICK_WORK_RISK,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-21',
            'document' => UploadedFile::fake()->create('certificado.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(self::LOCATION, Absence::first()->document_url);
    }

    public function test_a_failed_upload_does_not_create_the_record(): void
    {
        $this->actingAsUserWith('ausencias.registrar');

        Http::fake(['upload.example.net/*' => Http::response('', 500)]);

        $employee = Employee::factory()->create();

        $this->post("/api/employees/{$employee->id}/absences", [
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-09',
            'document' => UploadedFile::fake()->create('certificado.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(502);

        $this->assertDatabaseCount('absences', 0);
    }

    public function test_rejects_a_document_that_is_not_pdf_or_image(): void
    {
        $this->actingAsUserWith('ausencias.registrar');
        $employee = Employee::factory()->create();

        $this->post("/api/employees/{$employee->id}/absences", [
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-09',
            'document' => UploadedFile::fake()->create('certificado.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }

    public function test_rejects_an_overlap_with_another_absence(): void
    {
        $this->actingAsUserWith('ausencias.registrar');
        $employee = Employee::factory()->create();

        $employee->absences()->create([
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-09',
        ]);

        $this->postJson("/api/employees/{$employee->id}/absences", [
            'type' => Absence::PAID_LEAVE,
            'starts_on' => '2026-09-09',
            'ends_on' => '2026-09-10',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_on');
    }

    public function test_updates_an_absence_without_colliding_with_itself(): void
    {
        $this->actingAsUserWith('ausencias.registrar');
        $employee = Employee::factory()->create();

        $absence = $employee->absences()->create([
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-09',
            'document_url' => self::LOCATION,
        ]);

        $this->postJson("/api/absences/{$absence->id}", [
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-11',
            'folio' => 'XY987',
            'remove_document' => true,
        ])->assertOk()
            ->assertJsonPath('data.ends_on', '2026-09-11')
            ->assertJsonPath('data.folio', 'XY987')
            ->assertJsonPath('data.document_url', null);
    }

    public function test_lists_and_deletes_absences_of_an_employee(): void
    {
        $this->actingAsUserWith('ausencias.ver', 'ausencias.registrar');
        $employee = Employee::factory()->create();

        $id = $this->postJson("/api/employees/{$employee->id}/absences", [
            'type' => Absence::PATERNITY,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-11',
        ])->assertCreated()
            ->assertJsonPath('data.is_incapacity', false)
            ->json('data.id');

        $this->getJson("/api/employees/{$employee->id}/absences")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/absences/{$id}")->assertNoContent();

        $this->getJson("/api/employees/{$employee->id}/absences")->assertJsonCount(0, 'data');
        // Baja lógica: el registro sigue en la tabla.
        $this->assertDatabaseCount('absences', 1);
    }

    public function test_the_global_list_filters_by_type_and_overlap(): void
    {
        $this->actingAsUserWith('ausencias.ver');

        $first = Employee::factory()->create();
        $second = Employee::factory()->create();

        $first->absences()->create([
            'type' => Absence::MATERNITY,
            'starts_on' => '2026-08-20',
            'ends_on' => '2026-11-11',
        ]);
        $second->absences()->create([
            'type' => Absence::PAID_LEAVE,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-02',
        ]);

        $this->getJson('/api/absences?from=2026-09-15&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', Absence::MATERNITY)
            ->assertJsonPath('data.0.employee.id', $first->id);

        $this->getJson('/api/absences?type='.Absence::PAID_LEAVE)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $second->id);
    }

    public function test_conflicts_report_overlapping_vacations_without_blocking(): void
    {
        $this->actingAsUserWith('ausencias.ver');
        $employee = Employee::factory()->create();

        $employee->vacationRequests()->create([
            'starts_on' => '2026-09-08',
            'ends_on' => '2026-09-10',
            'requested_days' => 3,
            'status' => VacationRequest::APPROVED,
        ]);

        $this->postJson("/api/employees/{$employee->id}/absences/conflicts", [
            'starts_on' => '2026-09-09',
            'ends_on' => '2026-09-12',
        ])->assertOk()
            ->assertJsonCount(1, 'data.vacation_requests')
            ->assertJsonCount(0, 'data.absences');
    }

    public function test_an_incapacity_only_marks_the_working_days(): void
    {
        $employee = $this->employeeWithShift();

        // Del viernes al lunes: el sábado y el domingo siguen siendo descanso.
        $employee->absences()->create([
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-11',
            'ends_on' => '2026-09-14',
        ]);

        $calendar = app(AttendanceCalendarService::class)->build(
            $employee,
            CarbonImmutable::parse('2026-09-10'),
            CarbonImmutable::parse('2026-09-15'),
        );

        $statuses = collect($calendar['days'])->pluck('status', 'date');

        $this->assertSame(AttendanceCalendarService::STATUS_ABSENT, $statuses['2026-09-10']);
        $this->assertSame(AttendanceCalendarService::STATUS_SICK_LEAVE, $statuses['2026-09-11']);
        $this->assertSame(AttendanceCalendarService::STATUS_REST, $statuses['2026-09-12']);
        $this->assertSame(AttendanceCalendarService::STATUS_REST, $statuses['2026-09-13']);
        $this->assertSame(AttendanceCalendarService::STATUS_SICK_LEAVE, $statuses['2026-09-14']);
        $this->assertSame(AttendanceCalendarService::STATUS_ABSENT, $statuses['2026-09-15']);

        $this->assertSame(2, $calendar['summary'][AttendanceCalendarService::STATUS_SICK_LEAVE]);
        // Los días justificados no cuentan como programados ni castigan la asistencia.
        $this->assertSame(2, $calendar['summary']['scheduled_days']);
        $this->assertSame('Incapacidad por enfermedad general', collect($calendar['days'])->firstWhere('date', '2026-09-11')['absence']['type_label']);
    }

    public function test_a_leave_wins_over_an_approved_vacation_and_punching_counts_as_worked(): void
    {
        $employee = $this->employeeWithShift();

        $period = $employee->vacationPeriods()->create([
            'year_number' => 1,
            'starts_on' => '2026-01-15',
            'ends_on' => '2027-01-14',
            'expires_on' => '2027-07-14',
            'entitled_days' => 12,
        ]);
        $request = $employee->vacationRequests()->create([
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-09-11',
            'requested_days' => 2,
            'status' => VacationRequest::APPROVED,
        ]);
        $request->days()->create(['vacation_period_id' => $period->id, 'date' => '2026-09-10', 'days' => 1]);
        $request->days()->create(['vacation_period_id' => $period->id, 'date' => '2026-09-11', 'days' => 1]);

        $employee->absences()->create([
            'type' => Absence::PAID_LEAVE,
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-09-11',
        ]);

        AttendancePunch::create([
            'employee_id' => $employee->id,
            'pin' => $employee->rfc,
            'punched_at' => '2026-09-11 09:00:00',
            'punch_type' => AttendancePunch::TYPE_IN,
            'source' => 'device',
        ]);

        $calendar = app(AttendanceCalendarService::class)->build(
            $employee,
            CarbonImmutable::parse('2026-09-10'),
            CarbonImmutable::parse('2026-09-11'),
        );

        $statuses = collect($calendar['days'])->pluck('status', 'date');

        $this->assertSame(AttendanceCalendarService::STATUS_LEAVE, $statuses['2026-09-10']);
        $this->assertSame(AttendanceCalendarService::STATUS_WORKED, $statuses['2026-09-11']);
    }

    public function test_requires_permission(): void
    {
        $this->actingAsUserWith('ausencias.ver');
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/absences", [
            'type' => Absence::SICK_GENERAL,
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-09',
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/absences')->assertForbidden();
    }
}
