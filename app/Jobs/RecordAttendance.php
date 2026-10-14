<?php

namespace App\Jobs;

use App\Services\AttendanceRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalcula el resumen diario de uno o varios empleados en un rango.
 *
 * Lo despachan los disparadores (checada nueva, ausencia, excepción, vacación,
 * cambio de turno, festivo). Va por la cola para no alentar al checador ni a
 * RH; con QUEUE_CONNECTION=sync se ejecuta en el momento.
 */
class RecordAttendance implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>|null  $employeeIds  Nulo = toda la plantilla.
     */
    public function __construct(
        public readonly ?array $employeeIds,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function handle(AttendanceRecorder $recorder): void
    {
        $recorder->recordAll(
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->to),
            $this->employeeIds,
        );
    }

    /**
     * Un empleado desde una fecha; sin `to`, hasta ayer. Un rango que no
     * alcanza ningún día cerrado no encola nada.
     */
    public static function forEmployee(int $employeeId, CarbonInterface $from, ?CarbonInterface $to = null): void
    {
        self::forEmployees([$employeeId], $from, $to);
    }

    /**
     * @param  list<int>  $employeeIds
     */
    public static function forEmployees(array $employeeIds, CarbonInterface $from, ?CarbonInterface $to = null): void
    {
        if ($employeeIds === []) {
            return;
        }

        self::dispatchClamped($employeeIds, $from, $to);
    }

    /** Toda la plantilla en una fecha: festivos y cambios de catálogo. */
    public static function forDate(CarbonInterface $date): void
    {
        self::dispatchClamped(null, $date, $date);
    }

    /**
     * @param  list<int>|null  $employeeIds
     */
    private static function dispatchClamped(?array $employeeIds, CarbonInterface $from, ?CarbonInterface $to): void
    {
        $yesterday = CarbonImmutable::now(config('time_clock.timezone'))->startOfDay()->subDay();
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = $to === null ? $yesterday : min(CarbonImmutable::instance($to)->startOfDay(), $yesterday);

        if ($from->gt($to)) {
            return;
        }

        self::dispatch($employeeIds, $from->toDateString(), $to->toDateString());
    }
}
