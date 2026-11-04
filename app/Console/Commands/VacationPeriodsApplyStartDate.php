<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\VacationPeriod;
use App\Services\VacationPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Aplica la fecha de corte a los periodos ya generados.
 *
 * Al instalar el módulo los periodos nacieron desde la fecha de ingreso, como
 * si nadie hubiera tomado vacaciones nunca. Sin el histórico de lo que cada
 * quien gozó, ese saldo no se puede comprobar; este comando lo descarta.
 */
class VacationPeriodsApplyStartDate extends Command
{
    protected $signature = 'vacations:apply-start-date
                            {--employee=* : IDs a revisar; por omisión, todos}
                            {--dry-run : Muestra lo que se borraría sin tocar la base}';

    protected $description = 'Descarta los periodos vacacionales cerrados antes de la fecha de corte';

    public function __construct(private readonly VacationPeriodService $periods)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $startDate = VacationPeriodService::startDate();

        if ($startDate === null) {
            $this->error('No hay fecha de corte configurada. Define VACATIONS_START_DATE en el .env.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            'Fecha de corte: <info>%s</info>. Se descartan los periodos cerrados antes de ese día.',
            $startDate->toDateString(),
        ));

        $employees = $this->employees();

        if ($employees->isEmpty()) {
            $this->warn('No hay empleados que revisar.');

            return self::SUCCESS;
        }

        $affected = 0;
        $daysDropped = 0.0;
        $kept = 0;

        foreach ($employees as $employee) {
            $stale = $this->stalePeriods($employee, $startDate);

            if ($stale->isEmpty()) {
                continue;
            }

            $affected++;
            $droppable = $stale->filter(fn (VacationPeriod $period) => $period->taken_days == 0);
            $withDays = $stale->filter(fn (VacationPeriod $period) => $period->taken_days > 0);

            $daysDropped += $droppable->sum(fn (VacationPeriod $period) => $period->remaining_days);

            $this->reportEmployee($employee, $stale);

            if ($withDays->isNotEmpty()) {
                $kept++;
                $this->warn(sprintf(
                    '  Se conservan %d periodo(s) con días tomados: años %s.',
                    $withDays->count(),
                    $withDays->pluck('year_number')->join(', '),
                ));
            }

            if (! $dryRun) {
                $this->periods->ensurePeriods($employee->fresh());
            }
        }

        $this->line('');
        $this->info(sprintf(
            '%s %s día(s) de %d empleado(s), de %d revisado(s).',
            $dryRun ? 'Se descartarían' : 'Descartados',
            rtrim(rtrim(number_format($daysDropped, 1), '0'), '.'),
            $affected,
            $employees->count(),
        ));

        if ($kept > 0) {
            $this->warn(sprintf('%d empleado(s) conservan periodos previos al corte por tener días tomados.', $kept));
        }

        if ($dryRun) {
            $this->line('');
            $this->comment('Fue un ensayo: no se modificó nada. Corre el comando sin --dry-run para aplicarlo.');
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
     * @return Collection<int, VacationPeriod>
     */
    private function stalePeriods(Employee $employee, CarbonImmutable $startDate): Collection
    {
        return $employee->vacationPeriods()
            ->oldestFirst()
            ->get()
            ->filter(fn (VacationPeriod $period) => $period->ends_on->lt($startDate));
    }

    /**
     * @param  Collection<int, VacationPeriod>  $stale
     */
    private function reportEmployee(Employee $employee, Collection $stale): void
    {
        $this->line('');
        $this->line(sprintf(
            '<info>#%d %s</info> — ingreso %s, %d año(s) de antigüedad',
            $employee->id,
            $employee->full_name,
            $employee->hire_date->toDateString(),
            $employee->yearsOfServiceOn(),
        ));

        $this->table(
            ['Año', 'Cerró', 'Sin gozar', 'Tomados', 'Acción'],
            $stale->map(fn (VacationPeriod $period) => [
                $period->year_number,
                $period->ends_on->toDateString(),
                rtrim(rtrim(number_format($period->remaining_days, 1), '0'), '.'),
                rtrim(rtrim(number_format($period->taken_days, 1), '0'), '.'),
                $period->taken_days > 0 ? 'SE CONSERVA (tiene días tomados)' : 'descartar',
            ])->all(),
        );
    }
}
