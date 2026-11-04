<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\VacationPeriod;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Datos de la ficha de una solicitud y del concentrado anual, los mismos para
 * el JSON del panel y para las plantillas del PDF.
 *
 * Las fechas se formatean aquí en español: el locale de la aplicación es
 * inglés y el PDF no debe depender de él.
 */
class VacationSheetService
{
    public function __construct(private readonly VacationPeriodService $periods) {}

    /**
     * @return array{
     *     request: VacationRequest,
     *     employee: array<string, mixed>,
     *     period: ?VacationPeriod,
     *     entitled_days: int,
     *     available_days: float
     * }
     */
    public function sheet(VacationRequest $request): array
    {
        $request->loadMissing(['employee.subDepartment.department', 'requestedBy']);

        $employee = $request->employee;
        $summary = $this->periods->summary($employee);
        $period = $this->periodFor($request, $summary['periods']);

        return [
            'request' => $request,
            'employee' => $this->employeeData($employee),
            'period' => $period,
            // Sin periodo todavía (no cumple el año), los días que le corresponderán.
            'entitled_days' => $period?->entitled_days ?? $summary['next_entitlement_days'],
            'available_days' => $summary['available_days'],
        ];
    }

    /**
     * Solicitudes aprobadas con días cargados al periodo. Las pendientes no se
     * listan, pero ya apartan saldo: se informan aparte para que los totales
     * cuadren con lo que resta.
     *
     * @return array{
     *     employee: array<string, mixed>,
     *     period: VacationPeriod,
     *     rows: Collection<int, array{request: VacationRequest, days: float}>,
     *     approved_days: float,
     *     pending_days: float
     * }
     */
    public function periodSummary(VacationPeriod $period): array
    {
        $employee = $period->employee()->with('subDepartment.department')->firstOrFail();

        // whereHas recibe un Builder y with una relación: sin tipo para servir a ambos.
        $ofPeriod = fn ($query) => $query->where('vacation_period_id', $period->id);

        $requests = VacationRequest::query()
            ->where('employee_id', $employee->id)
            ->consuming()
            ->whereHas('days', $ofPeriod)
            ->with(['days' => $ofPeriod])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        [$approved, $pending] = $requests->partition(
            fn (VacationRequest $request) => $request->status === VacationRequest::APPROVED,
        );

        $rows = $approved->map(fn (VacationRequest $request) => [
            'request' => $request,
            'days' => round((float) $request->days->sum('days'), 1),
        ])->values();

        return [
            'employee' => $this->employeeData($employee),
            'period' => $period,
            'rows' => $rows,
            'approved_days' => round($rows->sum('days'), 1),
            'pending_days' => round($pending->sum(fn (VacationRequest $request) => $request->days->sum('days')), 1),
        ];
    }

    /**
     * El periodo en que caen las vacaciones; si ninguno las cubre (un rango a
     * futuro sin periodo generado), el que está en curso.
     *
     * @param  Collection<int, VacationPeriod>  $periods
     */
    private function periodFor(VacationRequest $request, Collection $periods): ?VacationPeriod
    {
        return $periods->first(fn (VacationPeriod $period) => $period->coversDate($request->starts_on))
            ?? $periods->first(fn (VacationPeriod $period) => $period->is_current);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeData(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'hire_date' => $employee->hire_date?->toDateString(),
            'department' => $employee->department()?->name,
            'sub_department' => $employee->subDepartment?->name,
        ];
    }

    /** "5" o "5.5": medio día es válido, pero el entero no lleva decimales. */
    public static function days(float|int $days): string
    {
        return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');
    }

    /** "$1,250.00" */
    public static function money(float|int $amount): string
    {
        return '$'.number_format($amount, 2);
    }

    /** "25%" o "27.5%": el entero no lleva decimales. */
    public static function percent(float|int $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.').'%';
    }

    /** "08/09/2026" */
    public static function shortDate(CarbonInterface|string|null $date): string
    {
        return $date === null ? '' : self::carbon($date)->format('d/m/Y');
    }

    /** "8 de septiembre de 2026" */
    public static function longDate(CarbonInterface|string $date): string
    {
        return self::carbon($date)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }

    /** "Del 14 al 18 de septiembre de 2026", igual que describeRangeLong en el panel. */
    public static function describeRange(CarbonInterface|string $from, CarbonInterface|string $to): string
    {
        $from = self::carbon($from);
        $to = self::carbon($to);

        if ($from->isSameDay($to)) {
            return 'El '.self::longDate($from);
        }

        return 'Del '.self::longDate($from).' al '.self::longDate($to);
    }

    private static function carbon(CarbonInterface|string $date): CarbonInterface
    {
        return $date instanceof CarbonInterface ? $date : CarbonImmutable::parse($date);
    }
}
