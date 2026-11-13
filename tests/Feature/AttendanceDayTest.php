<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\AttendanceDayClosed;
use App\Services\AttendanceCalendarService;
use App\Services\AttendanceRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AttendanceDayTest extends TestCase
{
    /** Jueves y viernes; "hoy" se fija al sábado siguiente. */
    private const THURSDAY = '2026-09-10';

    private const FRIDAY = '2026-09-11';

    private const TODAY = '2026-09-12';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 10:00', config('time_clock.timezone')));

        $this->employee = $this->employeeWithShift();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Lunes a viernes de 09:00 a 18:00 con comida de 14:00 a 15:00. */
    private function employeeWithShift(): Employee
    {
        $employee = Employee::factory()->create(['hire_date' => '2024-01-15']);

        $shift = Shift::create([
            'name' => 'Oficina '.$employee->id,
            'tolerance_minutes' => 10,
            'break_tolerance_minutes' => 10,
        ]);
        $shift->syncDays(collect(range(1, 5))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'break_start' => '14:00',
            'break_end' => '15:00',
        ])->all());

        $employee->shiftAssignments()->create(['shift_id' => $shift->id, 'starts_on' => '2024-01-15']);

        return $employee;
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

    /**
     * @param  list<array{0: string, 1: int}>  $punches
     */
    private function punch(Employee $employee, string $date, array $punches): void
    {
        foreach ($punches as [$time, $type]) {
            AttendancePunch::create([
                'employee_id' => $employee->id,
                'pin' => $employee->rfc,
                'punched_at' => "{$date} {$time}:00",
                'punch_type' => $type,
                'source' => 'device',
            ]);
        }
    }

    private function record(Employee $employee, string $from, string $to): void
    {
        app(AttendanceRecorder::class)->record(
            $employee,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function calendar(Employee $employee, string $from, string $to): array
    {
        return app(AttendanceCalendarService::class)->build(
            $employee,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    public function test_building_the_calendar_stores_the_closed_days_but_not_today(): void
    {
        $this->punch($this->employee, self::THURSDAY, [['09:20', AttendancePunch::TYPE_IN], ['18:00', AttendancePunch::TYPE_OUT]]);

        $calendar = $this->calendar($this->employee, self::THURSDAY, self::TODAY);

        $thursday = collect($calendar['days'])->firstWhere('date', self::THURSDAY);
        $today = collect($calendar['days'])->firstWhere('date', self::TODAY);

        $this->assertSame(AttendanceCalendarService::STATUS_LATE, $thursday['status']);
        $this->assertNotNull($thursday['attendance_day_id']);
        $this->assertNull($today['attendance_day_id']);

        $this->assertDatabaseCount('attendance_days', 2);
        $this->assertDatabaseHas('attendance_days', [
            'employee_id' => $this->employee->id,
            'date' => self::THURSDAY,
            'status' => AttendanceCalendarService::STATUS_LATE,
            'late_minutes' => 20,
            'first_in' => '09:20',
        ]);
    }

    public function test_the_stored_evaluation_wins_over_a_fresh_one(): void
    {
        $this->record($this->employee, self::THURSDAY, self::THURSDAY);

        // La checada entra sin disparador (directo al modelo): el renglón
        // guardado sigue diciendo falta hasta que algo lo recalcule.
        $this->punch($this->employee, self::THURSDAY, [['09:00', AttendancePunch::TYPE_IN]]);

        $day = collect($this->calendar($this->employee, self::THURSDAY, self::THURSDAY)['days'])->first();

        $this->assertSame(AttendanceCalendarService::STATUS_ABSENT, $day['status']);
        $this->assertCount(1, $day['punches']);

        $this->record($this->employee, self::THURSDAY, self::THURSDAY);

        $day = collect($this->calendar($this->employee, self::THURSDAY, self::THURSDAY)['days'])->first();

        $this->assertSame(AttendanceCalendarService::STATUS_ON_TIME, $day['status']);
    }

    public function test_a_manual_punch_recalculates_the_day(): void
    {
        $this->actingAsUserWith('checador.extraoficial');
        $this->record($this->employee, self::THURSDAY, self::THURSDAY);

        $this->assertDatabaseHas('attendance_days', [
            'employee_id' => $this->employee->id,
            'date' => self::THURSDAY,
            'status' => AttendanceCalendarService::STATUS_ABSENT,
        ]);

        $this->postJson('/api/time-clock/punches', [
            'employee_id' => $this->employee->id,
            'punched_at' => self::THURSDAY.' 09:05',
            'punch_type' => AttendancePunch::TYPE_IN,
            'notes' => 'Olvidó checar',
        ])->assertCreated();

        $this->assertDatabaseHas('attendance_days', [
            'employee_id' => $this->employee->id,
            'date' => self::THURSDAY,
            'status' => AttendanceCalendarService::STATUS_ON_TIME,
            'first_in' => '09:05',
        ]);
    }

    public function test_a_punch_from_the_device_recalculates_the_day(): void
    {
        $this->record($this->employee, self::THURSDAY, self::THURSDAY);

        $body = implode("\t", [$this->employee->rfc, self::THURSDAY.' 09:30:00', '0', '1', '0'])."\n";

        $this->call('POST', '/iclock/cdata?SN=TEST123&table=ATTLOG&Stamp=1', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $body)->assertOk();

        $this->assertDatabaseHas('attendance_days', [
            'employee_id' => $this->employee->id,
            'date' => self::THURSDAY,
            'status' => AttendanceCalendarService::STATUS_LATE,
            'late_minutes' => 30,
        ]);
    }

    public function test_an_absence_registered_later_excuses_the_stored_days(): void
    {
        $this->actingAsUserWith('ausencias.registrar');
        $this->record($this->employee, self::THURSDAY, self::FRIDAY);

        $this->postJson("/api/employees/{$this->employee->id}/absences", [
            'type' => Absence::SICK_GENERAL,
            'starts_on' => self::THURSDAY,
            'ends_on' => self::FRIDAY,
            'folio' => 'AB1',
        ])->assertCreated();

        $this->assertSame(
            [AttendanceCalendarService::STATUS_SICK_LEAVE, AttendanceCalendarService::STATUS_SICK_LEAVE],
            $this->employee->attendanceDays()->orderBy('date')->pluck('status')->all(),
        );
    }

    public function test_the_close_command_records_every_employee_and_notifies(): void
    {
        Notification::fake();

        $watcher = User::factory()->create()->givePermissionTo(Permission::findOrCreate('asistencia.ver', 'web'));
        $bystander = User::factory()->create();

        $other = $this->employeeWithShift();
        $this->punch($other, self::FRIDAY, [['09:25', AttendancePunch::TYPE_IN], ['18:00', AttendancePunch::TYPE_OUT]]);

        $this->artisan('attendance:close', ['--days' => 2])
            ->expectsOutputToContain('Cerrado '.self::FRIDAY)
            ->assertSuccessful();

        // Dos empleados por dos días.
        $this->assertDatabaseCount('attendance_days', 4);
        $this->assertDatabaseHas('attendance_days', [
            'employee_id' => $this->employee->id,
            'date' => self::FRIDAY,
            'status' => AttendanceCalendarService::STATUS_ABSENT,
        ]);
        $this->assertDatabaseHas('attendance_days', [
            'employee_id' => $other->id,
            'date' => self::FRIDAY,
            'status' => AttendanceCalendarService::STATUS_LATE,
        ]);

        Notification::assertSentTo($watcher, AttendanceDayClosed::class, function (AttendanceDayClosed $notification) use ($watcher) {
            $data = $notification->toArray($watcher);

            return $notification->absences === 1
                && $notification->lateArrivals === 1
                && $data['url'] === '/incidencias?from='.self::FRIDAY.'&to='.self::FRIDAY
                && str_contains($data['message'], '1 falta, 1 retardo');
        });
        Notification::assertNotSentTo($bystander, AttendanceDayClosed::class);
    }

    public function test_the_close_command_refuses_today(): void
    {
        $this->artisan('attendance:close', ['--date' => self::TODAY])->assertFailed();

        $this->assertDatabaseCount('attendance_days', 0);
    }

    public function test_a_justification_survives_recalculation_and_changes_the_summary(): void
    {
        $user = $this->actingAsUserWith('asistencia.justificar');
        $this->punch($this->employee, self::THURSDAY, [['09:40', AttendancePunch::TYPE_IN], ['18:00', AttendancePunch::TYPE_OUT]]);
        $this->record($this->employee, self::THURSDAY, self::FRIDAY);

        $day = $this->employee->attendanceDays()->whereDate('date', self::THURSDAY)->first();

        $this->postJson("/api/attendance/days/{$day->id}/justify", ['reason' => 'Cita médica avisada'])
            ->assertOk()
            ->assertJsonPath('data.status', AttendanceCalendarService::STATUS_LATE)
            ->assertJsonPath('data.justified.reason', 'Cita médica avisada')
            ->assertJsonPath('data.justified.by.id', $user->id);

        $this->record($this->employee, self::THURSDAY, self::THURSDAY);

        $this->assertDatabaseHas('attendance_days', [
            'id' => $day->id,
            'status' => AttendanceCalendarService::STATUS_LATE,
            'justification' => 'Cita médica avisada',
        ]);

        $summary = $this->calendar($this->employee, self::THURSDAY, self::FRIDAY)['summary'];

        $this->assertSame(0, $summary[AttendanceCalendarService::STATUS_LATE]);
        $this->assertSame(1, $summary['justified_late']);
        $this->assertSame(1, $summary[AttendanceCalendarService::STATUS_ABSENT]);
        $this->assertSame(40, $summary['late_minutes']);
        $this->assertSame(2, $summary['scheduled_days']);
        $this->assertSame(50, $summary['attendance_rate']);

        $this->deleteJson("/api/attendance/days/{$day->id}/justify")
            ->assertOk()
            ->assertJsonPath('data.justified', null);
    }

    public function test_a_justified_absence_stops_counting_as_scheduled(): void
    {
        $this->record($this->employee, self::THURSDAY, self::FRIDAY);

        $this->employee->attendanceDays()->whereDate('date', self::THURSDAY)->update([
            'justification' => 'Permiso verbal',
            'justified_at' => now(),
        ]);

        $summary = $this->calendar($this->employee, self::THURSDAY, self::FRIDAY)['summary'];

        $this->assertSame(1, $summary['justified_absent']);
        $this->assertSame(1, $summary[AttendanceCalendarService::STATUS_ABSENT]);
        $this->assertSame(1, $summary['scheduled_days']);
        $this->assertSame(0, $summary['attendance_rate']);
    }

    public function test_a_locked_day_is_not_recalculated_nor_justified(): void
    {
        $this->actingAsUserWith('asistencia.justificar');
        $this->record($this->employee, self::THURSDAY, self::THURSDAY);

        $day = $this->employee->attendanceDays()->first();
        $day->update(['locked_at' => now()]);

        $this->punch($this->employee, self::THURSDAY, [['09:00', AttendancePunch::TYPE_IN]]);
        $this->record($this->employee, self::THURSDAY, self::THURSDAY);

        $this->assertSame(AttendanceCalendarService::STATUS_ABSENT, $day->refresh()->status);

        $this->postJson("/api/attendance/days/{$day->id}/justify", ['reason' => 'Tarde'])
            ->assertUnprocessable();
    }

    public function test_lunch_over_the_tolerance_is_an_incident(): void
    {
        $this->punch($this->employee, self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:25', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);
        $this->punch($this->employee, self::FRIDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:08', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);

        $calendar = $this->calendar($this->employee, self::THURSDAY, self::FRIDAY);
        $days = collect($calendar['days'])->keyBy('date');

        $this->assertTrue($days[self::THURSDAY]['break_exceeded']);
        $this->assertSame(25, $days[self::THURSDAY]['break_overrun_minutes']);
        $this->assertFalse($days[self::FRIDAY]['break_exceeded']);
        $this->assertSame(1, $calendar['summary']['break_exceeded']);
    }

    public function test_the_incidents_endpoint_filters_by_type_and_reports_totals(): void
    {
        $this->actingAsUserWith('asistencia.ver');

        $other = $this->employeeWithShift();
        $this->punch($other, self::THURSDAY, [['09:30', AttendancePunch::TYPE_IN], ['18:00', AttendancePunch::TYPE_OUT]]);
        $this->punch($other, self::FRIDAY, [['09:00', AttendancePunch::TYPE_IN]]);

        app(AttendanceRecorder::class)->recordAll(CarbonImmutable::parse(self::THURSDAY), CarbonImmutable::parse(self::FRIDAY));

        $this->getJson('/api/attendance/incidents?from='.self::THURSDAY.'&to='.self::FRIDAY)
            ->assertOk()
            // Dos faltas, un retardo y una salida sin checar.
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.totals.absent', 2)
            ->assertJsonPath('meta.totals.late', 1)
            ->assertJsonPath('meta.totals.missing_out', 1)
            ->assertJsonPath('meta.totals.justified', 0);

        $this->getJson('/api/attendance/incidents?from='.self::THURSDAY.'&to='.self::FRIDAY.'&type=late')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $other->id)
            ->assertJsonPath('data.0.incidents', ['late'])
            ->assertJsonPath('data.0.shift.name', 'Oficina '.$other->id);

        $this->getJson('/api/attendance/incidents?from='.self::THURSDAY.'&to='.self::FRIDAY.'&type=missing_out')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', self::FRIDAY);

        $this->getJson('/api/attendance/incidents?from='.self::THURSDAY.'&to='.self::FRIDAY.'&employee_id='.$this->employee->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_incidents_endpoint_requires_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/attendance/incidents')->assertForbidden();

        $this->actingAsUserWith('asistencia.ver');
        $this->record($this->employee, self::THURSDAY, self::THURSDAY);
        $day = $this->employee->attendanceDays()->first();

        $this->postJson("/api/attendance/days/{$day->id}/justify", ['reason' => 'x'])->assertForbidden();
    }

    public function test_recording_skips_the_future_and_the_days_before_hire(): void
    {
        $employee = Employee::factory()->create(['hire_date' => self::FRIDAY]);

        $rows = app(AttendanceRecorder::class)->record(
            $employee,
            CarbonImmutable::parse(self::THURSDAY),
            CarbonImmutable::parse('2026-09-20'),
        );

        $this->assertSame([self::FRIDAY], $rows->map(fn (AttendanceDay $row) => $row->date->toDateString())->all());
    }
}
