<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\HolidayRule;
use App\Models\Shift;
use App\Models\VacationRequest;
use App\Services\AttendanceCalendarService;
use App\Services\ExpectedSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceCalendarTest extends TestCase
{
    use RefreshDatabase;

    /** Jueves y viernes de una semana pasada, para que el día ya esté evaluado. */
    private const THURSDAY = '2026-09-10';

    private const FRIDAY = '2026-09-11';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->create(['hire_date' => '2024-01-15']);

        $shift = Shift::create([
            'name' => 'Oficina',
            'tolerance_minutes' => 10,
            'absence_after_minutes' => 60,
        ]);

        $shift->syncDays(collect(range(1, 5))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'break_start' => '14:00',
            'break_end' => '15:00',
        ])->all());

        $this->employee->shiftAssignments()->create([
            'shift_id' => $shift->id,
            'starts_on' => '2024-01-15',
        ]);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $punches  [hora, tipo]
     */
    private function punch(string $date, array $punches): void
    {
        foreach ($punches as [$time, $type]) {
            AttendancePunch::create([
                'employee_id' => $this->employee->id,
                'pin' => $this->employee->rfc,
                'punched_at' => "{$date} {$time}:00",
                'punch_type' => $type,
                'source' => 'device',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dayOf(string $date): array
    {
        $timezone = config('time_clock.timezone');

        $calendar = app(AttendanceCalendarService::class)->build(
            $this->employee,
            CarbonImmutable::parse(self::THURSDAY, $timezone),
            CarbonImmutable::parse(self::FRIDAY, $timezone),
        );

        return collect($calendar['days'])->firstWhere('date', $date);
    }

    public function test_pairs_four_punches_into_entry_lunch_and_exit(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:00', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(AttendanceCalendarService::STATUS_ON_TIME, $day['status']);
        $this->assertSame(['09:00', '14:00', '15:00', '18:00'], [
            $day['first_in'], $day['break_out'], $day['break_in'], $day['last_out'],
        ]);
        $this->assertSame(480, $day['worked_minutes']);
        $this->assertSame(60, $day['break_minutes']);
        $this->assertSame(0, $day['break_overrun_minutes']);
    }

    public function test_records_the_minutes_exceeded_at_lunch(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:25', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(85, $day['break_minutes']);
        $this->assertSame(25, $day['break_overrun_minutes']);
        $this->assertSame(455, $day['worked_minutes']);
        $this->assertSame(AttendanceCalendarService::STATUS_ON_TIME, $day['status']);
    }

    public function test_two_punches_are_entry_and_exit_without_lunch(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:05', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame('09:05', $day['first_in']);
        $this->assertSame('18:00', $day['last_out']);
        $this->assertNull($day['break_out']);
        $this->assertSame(0, $day['break_minutes']);
        $this->assertSame(535, $day['worked_minutes']);
    }

    public function test_a_repeated_punch_within_three_minutes_is_ignored(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['09:02', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:00', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
            ['18:03', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(['09:00', '14:00', '15:00', '18:00'], [
            $day['first_in'], $day['break_out'], $day['break_in'], $day['last_out'],
        ]);
        $this->assertSame(480, $day['worked_minutes']);
        $this->assertFalse($day['irregular_punches']);
        $this->assertSame(
            ['09:02', '18:03'],
            collect($day['punches'])->where('is_duplicate', true)->pluck('time')->all(),
        );
    }

    public function test_a_punch_after_the_window_is_not_a_duplicate(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['09:04', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame('09:04', $day['last_out']);
        $this->assertFalse(collect($day['punches'])->contains('is_duplicate', true));
    }

    public function test_ending_on_an_entry_means_the_exit_is_missing(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:00', AttendancePunch::TYPE_IN],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(['14:00', '15:00'], [$day['break_out'], $day['break_in']]);
        $this->assertNull($day['last_out']);
        $this->assertTrue($day['missing_out']);
        $this->assertFalse($day['irregular_punches']);
    }

    public function test_every_absence_during_the_day_is_discounted(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['11:00', AttendancePunch::TYPE_OUT],
            ['11:30', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:00', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(['14:00', '15:00'], [$day['break_out'], $day['break_in']]);
        $this->assertSame(90, $day['break_minutes']);
        $this->assertSame(450, $day['worked_minutes']);
    }

    public function test_an_early_lunch_is_still_the_lunch(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['10:00', AttendancePunch::TYPE_OUT],
            ['10:15', AttendancePunch::TYPE_IN],
            ['13:10', AttendancePunch::TYPE_OUT],
            ['13:55', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(['13:10', '13:55'], [$day['break_out'], $day['break_in']]);
        $this->assertSame(60, $day['break_minutes']);
    }

    public function test_an_exit_during_lunch_time_today_is_a_lunch_in_progress(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::THURSDAY.' 14:30', config('time_clock.timezone')));

        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:05', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame('14:05', $day['break_out']);
        $this->assertNull($day['break_in']);
        $this->assertNull($day['last_out']);
        $this->assertFalse($day['missing_out']);
    }

    public function test_a_past_day_ending_at_lunch_time_is_missing_the_return(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:05', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame('14:05', $day['break_out']);
        $this->assertNull($day['last_out']);
        $this->assertTrue($day['missing_out']);
    }

    public function test_an_exit_outside_lunch_time_is_the_exit(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['13:55', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame('13:55', $day['last_out']);
        $this->assertNull($day['break_out']);
    }

    public function test_punches_with_the_wrong_button_are_flagged_for_review(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_IN],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertTrue($day['irregular_punches']);
        $this->assertSame(['09:00', '18:00'], [$day['first_in'], $day['last_out']]);
        $this->assertFalse($day['missing_out']);
    }

    public function test_overtime_punches_do_not_alter_the_workday(): void
    {
        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
            ['18:30', AttendancePunch::TYPE_OVERTIME_IN],
            ['20:00', AttendancePunch::TYPE_OVERTIME_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame('18:00', $day['last_out']);
        $this->assertNull($day['break_out']);
        $this->assertFalse($day['irregular_punches']);
        $this->assertCount(4, $day['punches']);
    }

    public function test_a_rest_holiday_replaces_the_shift(): void
    {
        Holiday::create([
            'date' => self::THURSDAY,
            'name' => 'Día de prueba',
            'observance' => Holiday::REST,
            'is_mandatory' => true,
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(AttendanceCalendarService::STATUS_HOLIDAY, $day['status']);
        $this->assertSame(ExpectedSchedule::SOURCE_HOLIDAY, $day['schedule_source']);
        $this->assertNull($day['expected']);
        $this->assertSame('Día de prueba', $day['holiday']['name']);
    }

    public function test_a_special_hours_holiday_overrides_the_shift_schedule(): void
    {
        Holiday::create([
            'date' => self::THURSDAY,
            'name' => 'Nochebuena',
            'observance' => Holiday::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ]);

        $this->punch(self::THURSDAY, [
            ['09:03', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(ExpectedSchedule::SOURCE_HOLIDAY, $day['schedule_source']);
        $this->assertSame(['09:00', '14:00'], [$day['expected']['start'], $day['expected']['end']]);
        $this->assertSame(300, $day['expected']['work_minutes']);
        $this->assertSame(AttendanceCalendarService::STATUS_ON_TIME, $day['status']);
    }

    public function test_a_holiday_from_the_rules_catalog_reaches_the_calendar(): void
    {
        HolidayRule::create([
            'name' => 'Festivo del catálogo',
            'rule_type' => HolidayRule::FIXED,
            'month' => 9,
            'day' => 10,
            'is_mandatory' => true,
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(AttendanceCalendarService::STATUS_HOLIDAY, $day['status']);
        $this->assertSame('Festivo del catálogo', $day['holiday']['name']);
    }

    public function test_a_rule_with_special_hours_replaces_the_shift_schedule(): void
    {
        HolidayRule::create([
            'name' => 'Nochebuena',
            'rule_type' => HolidayRule::FIXED,
            'month' => 9,
            'day' => 10,
            'observance' => HolidayRule::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ]);

        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(ExpectedSchedule::SOURCE_HOLIDAY, $day['schedule_source']);
        $this->assertSame(['09:00', '14:00'], [$day['expected']['start'], $day['expected']['end']]);
        $this->assertSame(AttendanceCalendarService::STATUS_ON_TIME, $day['status']);
    }

    public function test_a_normal_holiday_keeps_the_shift_schedule(): void
    {
        Holiday::create([
            'date' => self::THURSDAY,
            'name' => 'Festivo que se trabaja',
            'observance' => Holiday::NORMAL,
        ]);

        $this->punch(self::THURSDAY, [['09:00', AttendancePunch::TYPE_IN]]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(ExpectedSchedule::SOURCE_SHIFT, $day['schedule_source']);
        $this->assertSame('18:00', $day['expected']['end']);
        $this->assertSame('Festivo que se trabaja', $day['holiday']['name']);
    }

    public function test_an_employee_override_wins_over_the_holiday(): void
    {
        Holiday::create([
            'date' => self::THURSDAY,
            'name' => 'Nochebuena',
            'observance' => Holiday::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ]);

        $this->employee->scheduleOverrides()->create([
            'date' => self::THURSDAY,
            'start_time' => '10:00',
            'end_time' => '16:00',
            'reason' => 'Cubre a un compañero',
        ]);

        $this->punch(self::THURSDAY, [
            ['10:00', AttendancePunch::TYPE_IN],
            ['16:00', AttendancePunch::TYPE_OUT],
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(ExpectedSchedule::SOURCE_OVERRIDE, $day['schedule_source']);
        $this->assertSame(['10:00', '16:00'], [$day['expected']['start'], $day['expected']['end']]);
        $this->assertSame(AttendanceCalendarService::STATUS_ON_TIME, $day['status']);
        $this->assertSame('Cubre a un compañero', $day['override']['reason']);
        // El festivo se sigue informando aunque no mande sobre el horario.
        $this->assertSame('Nochebuena', $day['holiday']['name']);
    }

    public function test_an_override_can_turn_a_working_day_into_rest(): void
    {
        $this->employee->scheduleOverrides()->create([
            'date' => self::THURSDAY,
            'is_rest_day' => true,
            'reason' => 'Descanso movido',
        ]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(AttendanceCalendarService::STATUS_REST, $day['status']);
        $this->assertSame(ExpectedSchedule::SOURCE_OVERRIDE, $day['schedule_source']);
    }

    public function test_an_override_can_turn_a_rest_day_into_a_working_day(): void
    {
        $saturday = '2026-09-12';

        $this->employee->scheduleOverrides()->create([
            'date' => $saturday,
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);

        $calendar = app(AttendanceCalendarService::class)->build(
            $this->employee,
            CarbonImmutable::parse($saturday),
            CarbonImmutable::parse($saturday),
        );

        $day = $calendar['days'][0];

        $this->assertSame(AttendanceCalendarService::STATUS_ABSENT, $day['status']);
        $this->assertSame(240, $day['expected']['work_minutes']);
    }

    public function test_late_arrival_is_measured_against_the_resolved_schedule(): void
    {
        $this->employee->scheduleOverrides()->create([
            'date' => self::THURSDAY,
            'start_time' => '11:00',
            'end_time' => '19:00',
        ]);

        $this->punch(self::THURSDAY, [['11:30', AttendancePunch::TYPE_IN]]);

        $day = $this->dayOf(self::THURSDAY);

        $this->assertSame(AttendanceCalendarService::STATUS_LATE, $day['status']);
        $this->assertSame(30, $day['late_minutes']);
    }

    public function test_an_approved_vacation_day_is_not_an_absence(): void
    {
        $this->vacationOn(self::THURSDAY, VacationRequest::APPROVED);

        $calendar = app(AttendanceCalendarService::class)->build(
            $this->employee,
            CarbonImmutable::parse(self::THURSDAY),
            CarbonImmutable::parse(self::FRIDAY),
        );

        $day = collect($calendar['days'])->firstWhere('date', self::THURSDAY);

        $this->assertSame(AttendanceCalendarService::STATUS_VACATION, $day['status']);
        $this->assertSame(1, $calendar['summary'][AttendanceCalendarService::STATUS_VACATION]);
        $this->assertSame(1, $calendar['summary']['scheduled_days']);
    }

    public function test_a_pending_vacation_does_not_excuse_the_day(): void
    {
        $this->vacationOn(self::THURSDAY, VacationRequest::PENDING);

        $this->assertSame(AttendanceCalendarService::STATUS_ABSENT, $this->dayOf(self::THURSDAY)['status']);
    }

    private function vacationOn(string $date, string $status): void
    {
        $period = $this->employee->vacationPeriods()->create([
            'year_number' => 1,
            'starts_on' => '2026-01-15',
            'ends_on' => '2027-01-14',
            'expires_on' => '2027-07-14',
            'entitled_days' => 12,
        ]);

        $request = $this->employee->vacationRequests()->create([
            'starts_on' => $date,
            'ends_on' => $date,
            'requested_days' => 1,
            'status' => $status,
        ]);

        $request->days()->create(['vacation_period_id' => $period->id, 'date' => $date, 'days' => 1]);
    }

    public function test_summary_counts_holidays_and_lunch_overrun(): void
    {
        Holiday::create(['date' => self::FRIDAY, 'name' => 'Festivo', 'observance' => Holiday::REST]);

        $this->punch(self::THURSDAY, [
            ['09:00', AttendancePunch::TYPE_IN],
            ['14:00', AttendancePunch::TYPE_OUT],
            ['15:20', AttendancePunch::TYPE_IN],
            ['18:00', AttendancePunch::TYPE_OUT],
        ]);

        $calendar = app(AttendanceCalendarService::class)->build(
            $this->employee,
            CarbonImmutable::parse(self::THURSDAY),
            CarbonImmutable::parse(self::FRIDAY),
        );

        $summary = $calendar['summary'];

        $this->assertSame(1, $summary[AttendanceCalendarService::STATUS_HOLIDAY]);
        $this->assertSame(1, $summary[AttendanceCalendarService::STATUS_ON_TIME]);
        $this->assertSame(20, $summary['break_overrun_minutes']);
        // El festivo no cuenta como día programado ni castiga la asistencia.
        $this->assertSame(1, $summary['scheduled_days']);
        $this->assertSame(100, $summary['attendance_rate']);
    }
}
