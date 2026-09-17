<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\VacationPeriod;
use App\Services\VacationPeriodService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Realinea los periodos vacacionales con la fecha de ingreso vigente.
 *
 * Hace falta para los empleados cuya fecha de ingreso se corrigió después de
 * que ya se habían generado periodos: los viejos quedaron con los aniversarios
 * anteriores y el saldo salía inflado al sumar dos periodos vigentes a la vez.
 */
class VacationPeriodsRealign extends Command
{
    protected $signature = 'vacations:realign-periods
                            {--employee=* : IDs a realinear; por omisión, todos}
                            {--dry-run : Muestra los cambios sin guardarlos}';

    protected $description = 'Realinea los periodos vacacionales con la fecha de ingreso de cada empleado';

    public function __construct(private readonly VacationPeriodService $periods)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $employees = $this->employees();

        if ($employees->isEmpty()) {
            $this->warn('No hay empleados que revisar.');

            return self::SUCCESS;
        }

        $realigned = 0;
        $orphansLeft = 0;

        foreach ($employees as $employee) {
            $misaligned = $this->misalignedPeriods($employee);

            if ($misaligned->isEmpty()) {
                continue;
            }

            $realigned++;
            $this->reportEmployee($employee, $misaligned);

            $orphansWithDays = $this->periods->orphanPeriods($employee)
                ->filter(fn (VacationPeriod $period) => $period->taken_days > 0);

            if ($orphansWithDays->isNotEmpty()) {
                $orphansLeft++;
                $this->warn(sprintf(
                    '  Se conservan %d periodo(s) con días tomados: años %s. Revísalos a mano.',
                    $orphansWithDays->count(),
                    $orphansWithDays->pluck('year_number')->join(', '),
                ));
            }

            if (! $dryRun) {
                $this->periods->ensurePeriods($employee);
            }
        }

        $this->line('');
        $this->info(sprintf(
            '%s %d empleado(s) de %d revisado(s).',
            $dryRun ? 'Se realinearían' : 'Realineados',
            $realigned,
            $employees->count(),
        ));

        if ($orphansLeft > 0) {
            $this->warn(sprintf('%d empleado(s) quedaron con periodos que requieren revisión manual.', $orphansLeft));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employees(): Collection
    {
        $ids = $this->option('employee');

        return Employee::query()
            ->whereNotNull('hire_date')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();
    }

    /**
     * Periodos cuyas fechas no corresponden al aniversario que les toca, más
     * los que sobran por venir de una fecha de ingreso anterior.
     *
     * @return Collection<int, VacationPeriod>
     */
    private function misalignedPeriods(Employee $employee): Collection
    {
        $hireDate = $employee->hire_date;
        $orphans = $this->periods->orphanPeriods($employee);

        return $employee->vacationPeriods()
            ->oldestFirst()
            ->get()
            ->filter(fn (VacationPeriod $period) => $orphans->contains('id', $period->id)
                || ! $period->starts_on->isSameDay($hireDate->copy()->addYears($period->year_number)));
    }

    /**
     * @param  Collection<int, VacationPeriod>  $misaligned
     */
    private function reportEmployee(Employee $employee, Collection $misaligned): void
    {
        $this->line('');
        $this->line(sprintf(
            '<info>#%d %s</info> — ingreso %s',
            $employee->id,
            $employee->full_name,
            $employee->hire_date->toDateString(),
        ));

        $this->table(
            ['Año', 'Inicio actual', 'Inicio correcto', 'Tomados', 'Acción'],
            $misaligned->map(function (VacationPeriod $period) use ($employee) {
                $expected = $employee->hire_date->copy()->addYears($period->year_number);
                $orphan = $this->periods->orphanPeriods($employee)->contains('id', $period->id);

                return [
                    $period->year_number,
                    $period->starts_on->toDateString(),
                    $orphan ? '—' : $expected->toDateString(),
                    rtrim(rtrim(number_format($period->taken_days, 1), '0'), '.'),
                    $this->actionFor($period, $orphan),
                ];
            })->all(),
        );
    }

    private function actionFor(VacationPeriod $period, bool $orphan): string
    {
        if (! $orphan) {
            return 'realinear';
        }

        return $period->taken_days > 0 ? 'SE CONSERVA (tiene días tomados)' : 'eliminar';
    }
}
