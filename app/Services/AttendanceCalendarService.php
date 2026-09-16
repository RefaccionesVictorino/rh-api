<?php

namespace App\Services;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\ScheduleOverride;
use App\Models\Shift;
use App\Models\ShiftDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Asistencia día por día de un empleado en un rango de fechas.
 *
 * Se calcula al vuelo a partir de las checadas crudas, del turno vigente en
 * cada fecha y de las excepciones que apliquen. No se persiste: cambiar el
 * turno o recibir una checada atrasada del equipo debe reflejarse sin
 * recalcular nada.
 */
class AttendanceCalendarService
{
    public const STATUS_ON_TIME = 'on_time';

    public const STATUS_LATE = 'late';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_REST = 'rest';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_NO_SHIFT = 'no_shift';

    public const STATUS_PENDING = 'pending';

    public const STATUS_WORKED = 'worked';

    /**
     * En un turno nocturno la salida cae en el calendario del día siguiente.
     * Se buscan checadas hasta este margen después de la hora de salida.
     */
    private const NIGHT_EXIT_GRACE_MINUTES = 120;

    public function __construct(private readonly HolidayCalendar $holidays) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $today = CarbonImmutable::now(config('time_clock.timezone'))->startOfDay();

        $assignments = $employee->shiftAssignments()
            ->with('shift.days')
            ->overlapping($from, $to)
            ->orderBy('starts_on')
            ->get();

        $holidays = $this->holidays->between($from, $to);

        $overrides = $employee->scheduleOverrides()
            ->betweenDates($from, $to)
            ->get()
            ->keyBy(fn (ScheduleOverride $override) => $override->date->toDateString());

        // Un día más al final: la salida de un turno nocturno del último día
        // del rango queda en la madrugada siguiente.
        $punchesByDate = AttendancePunch::query()
            ->forEmployee($employee->id)
            ->betweenDates($from->toDateString(), $to->addDay()->toDateString())
            ->with('device:id,name')
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn (AttendancePunch $punch) => $punch->punched_at->toDateString());

        /** @var array<int, true> $consumed Checadas ya atribuidas al turno nocturno del día anterior. */
        $consumed = [];
        $days = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();

            /** @var EmployeeShift|null $assignment */
            $assignment = $assignments->first(fn (EmployeeShift $item) => $item->coversDate($date));
            $shift = $assignment?->shift;

            $expected = ExpectedSchedule::resolve(
                $shift?->dayFor($date->dayOfWeek),
                $holidays->get($key),
                $overrides->get($key),
            );

            $punches = ($punchesByDate->get($key) ?? collect())
                ->reject(fn (AttendancePunch $punch) => isset($consumed[$punch->id]))
                ->values();

            if ($expected->crossesMidnight()) {
                $next = $punchesByDate->get($date->addDay()->toDateString()) ?? collect();

                foreach ($this->nightExitPunches($expected, $next, $consumed) as $punch) {
                    $consumed[$punch->id] = true;
                    $punches->push($punch);
                }
            }

            $days[] = $this->day($date, $today, $shift, $expected, $punches);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $today->toDateString(),
            'days' => $days,
            'summary' => $this->summary($days),
        ];
    }

    /**
     * Checadas de la madrugada siguiente que corresponden a la salida del
     * turno nocturno: las que caen antes de la hora de salida más un margen y
     * no están marcadas como entrada.
     *
     * @param  Collection<int, AttendancePunch>  $next
     * @param  array<int, true>  $consumed
     * @return list<AttendancePunch>
     */
    private function nightExitPunches(ExpectedSchedule $expected, Collection $next, array $consumed): array
    {
        $window = $expected->workWindow();

        if ($window === null) {
            return [];
        }

        $limit = $window[1] - ShiftDay::MINUTES_PER_DAY + self::NIGHT_EXIT_GRACE_MINUTES;
        $taken = [];

        foreach ($next as $punch) {
            if (isset($consumed[$punch->id]) || $punch->punch_type === AttendancePunch::TYPE_IN) {
                continue;
            }

            if ($this->minutesOfDay($punch) <= $limit) {
                $taken[] = $punch;
            }
        }

        return $taken;
    }

    /**
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array<string, mixed>
     */
    private function day(
        CarbonImmutable $date,
        CarbonImmutable $today,
        ?Shift $shift,
        ExpectedSchedule $expected,
        Collection $punches,
    ): array {
        $window = $expected->workWindow();

        $pairs = $this->pairPunches($punches);
        $firstIn = $pairs['first_in'];
        $lastOut = $pairs['last_out'];

        $inMinutes = $firstIn === null ? null : $this->minutesOfDay($firstIn);
        $outMinutes = $lastOut === null ? null : $this->minutesOfDay($lastOut)
            + ($lastOut->punched_at->toDateString() === $date->toDateString() ? 0 : ShiftDay::MINUTES_PER_DAY);

        $breakMinutes = $this->breakMinutes($pairs['break_out'], $pairs['break_in']);

        $worked = $inMinutes !== null && $outMinutes !== null && $outMinutes > $inMinutes
            ? $outMinutes - $inMinutes - $breakMinutes
            : 0;

        $lateMinutes = 0;
        $breakOverrunMinutes = max(0, $breakMinutes - $expected->breakMinutes());

        if (! $expected->hasShift()) {
            $status = $punches->isEmpty() ? self::STATUS_NO_SHIFT : self::STATUS_WORKED;
        } elseif ($expected->isRestDay()) {
            $status = $punches->isEmpty()
                ? ($expected->holiday !== null ? self::STATUS_HOLIDAY : self::STATUS_REST)
                : self::STATUS_WORKED;
        } elseif ($punches->isEmpty()) {
            $status = $date->lt($today) ? self::STATUS_ABSENT : self::STATUS_PENDING;
        } else {
            $lateMinutes = max(0, $inMinutes - $window[0]);

            if ($shift?->absence_after_minutes !== null && $lateMinutes > $shift->absence_after_minutes) {
                $status = self::STATUS_ABSENT;
            } elseif ($lateMinutes > ($shift?->tolerance_minutes ?? 0)) {
                $status = self::STATUS_LATE;
            } else {
                $status = self::STATUS_ON_TIME;
            }
        }

        $isScheduled = in_array($status, [self::STATUS_ON_TIME, self::STATUS_LATE], true);
        $holiday = $expected->holiday;

        return [
            'date' => $date->toDateString(),
            'weekday' => $date->dayOfWeek,
            'is_today' => $date->equalTo($today),
            'status' => $status,
            'schedule_source' => $expected->source,
            'shift' => $shift === null ? null : [
                'id' => $shift->id,
                'name' => $shift->name,
                'code' => $shift->code,
            ],
            'holiday' => $holiday === null ? null : [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'observance' => $holiday->observance,
                'observance_label' => $holiday->observance_label,
                'is_mandatory' => $holiday->is_mandatory,
            ],
            'override' => $expected->override === null ? null : [
                'id' => $expected->override->id,
                'reason' => $expected->override->reason,
            ],
            'expected' => $window === null ? null : [
                'start' => $expected->startTime(),
                'end' => $expected->endTime(),
                'crosses_midnight' => $expected->crossesMidnight(),
                'work_minutes' => $expected->workMinutes(),
                'break_minutes' => $expected->breakMinutes(),
            ],
            'first_in' => $firstIn?->punched_at->format('H:i'),
            'last_out' => $lastOut?->punched_at->format('H:i'),
            'break_out' => $pairs['break_out']?->punched_at->format('H:i'),
            'break_in' => $pairs['break_in']?->punched_at->format('H:i'),
            'late_minutes' => $lateMinutes,
            'worked_minutes' => max(0, $worked),
            'break_minutes' => $breakMinutes,
            'break_overrun_minutes' => $breakOverrunMinutes,
            // Entró pero no hay salida registrada y el día ya pasó.
            'missing_out' => $isScheduled && $lastOut === null && $date->lt($today),
            'punches' => $punches->map(fn (AttendancePunch $punch) => [
                'id' => $punch->id,
                'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                'time' => $punch->punched_at->format('H:i'),
                'punch_type' => $punch->punch_type,
                'punch_type_label' => $punch->type_label,
                'verify_mode_label' => $punch->verify_mode_label,
                'source' => $punch->source,
                'device' => $punch->device?->name,
                'notes' => $punch->notes,
            ])->values()->all(),
        ];
    }

    /**
     * Reparte las checadas del día en entrada, comida y salida.
     *
     * El equipo manda entrada y salida alternadas sin distinguir la comida, así
     * que el par intermedio (una salida seguida de otra entrada) es la comida y
     * la última salida es la de casa.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array{first_in: ?AttendancePunch, break_out: ?AttendancePunch, break_in: ?AttendancePunch, last_out: ?AttendancePunch}
     */
    private function pairPunches(Collection $punches): array
    {
        $empty = ['first_in' => null, 'break_out' => null, 'break_in' => null, 'last_out' => null];

        if ($punches->isEmpty()) {
            return $empty;
        }

        $explicitBreakOut = $punches->first(fn (AttendancePunch $p) => $p->punch_type === AttendancePunch::TYPE_BREAK_OUT);

        if ($explicitBreakOut !== null) {
            return [
                'first_in' => $punches->first(fn (AttendancePunch $p) => $p->punch_type === AttendancePunch::TYPE_IN)
                    ?? $punches->first(),
                'break_out' => $explicitBreakOut,
                'break_in' => $punches->first(
                    fn (AttendancePunch $p) => $p->punch_type === AttendancePunch::TYPE_BREAK_IN
                        && $p->punched_at->gt($explicitBreakOut->punched_at)
                ),
                'last_out' => $punches->last(fn (AttendancePunch $p) => $p->punch_type === AttendancePunch::TYPE_OUT),
            ];
        }

        $ordered = $punches->sortBy(fn (AttendancePunch $p) => $p->punched_at)->values();

        $result = $empty;
        $result['first_in'] = $ordered->first();

        if ($ordered->count() > 1) {
            $result['last_out'] = $ordered->last();
        }

        // Cuatro checadas: entrada, salida a comer, regreso, salida.
        if ($ordered->count() >= 4) {
            $result['break_out'] = $ordered[1];
            $result['break_in'] = $ordered[2];
        }

        return $result;
    }

    private function breakMinutes(?AttendancePunch $out, ?AttendancePunch $in): int
    {
        if ($out === null || $in === null) {
            return 0;
        }

        return max(0, (int) $out->punched_at->diffInMinutes($in->punched_at));
    }

    private function minutesOfDay(AttendancePunch $punch): int
    {
        return $punch->punched_at->hour * 60 + $punch->punched_at->minute;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<string, int|null>
     */
    private function summary(array $days): array
    {
        $counts = array_fill_keys([
            self::STATUS_ON_TIME, self::STATUS_LATE, self::STATUS_ABSENT, self::STATUS_REST,
            self::STATUS_HOLIDAY, self::STATUS_NO_SHIFT, self::STATUS_PENDING, self::STATUS_WORKED,
        ], 0);

        $workedMinutes = 0;
        $expectedMinutes = 0;
        $lateMinutes = 0;
        $breakOverrunMinutes = 0;
        $missingOut = 0;

        foreach ($days as $day) {
            $counts[$day['status']]++;
            $workedMinutes += $day['worked_minutes'];
            $lateMinutes += $day['late_minutes'];
            $breakOverrunMinutes += $day['break_overrun_minutes'];
            $missingOut += $day['missing_out'] ? 1 : 0;

            // Solo los días ya evaluados suman jornada esperada.
            if (in_array($day['status'], [self::STATUS_ON_TIME, self::STATUS_LATE, self::STATUS_ABSENT], true)) {
                $expectedMinutes += $day['expected']['work_minutes'] ?? 0;
            }
        }

        $scheduled = $counts[self::STATUS_ON_TIME] + $counts[self::STATUS_LATE] + $counts[self::STATUS_ABSENT];

        return $counts + [
            'scheduled_days' => $scheduled,
            'attendance_rate' => $scheduled === 0
                ? null
                : (int) round(($counts[self::STATUS_ON_TIME] + $counts[self::STATUS_LATE]) / $scheduled * 100),
            'worked_minutes' => $workedMinutes,
            'expected_minutes' => $expectedMinutes,
            'late_minutes' => $lateMinutes,
            'break_overrun_minutes' => $breakOverrunMinutes,
            'missing_out' => $missingOut,
        ];
    }
}
