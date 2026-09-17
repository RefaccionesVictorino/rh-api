<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\ShiftAssignmentService;
use App\Services\VacationRequestService;
use App\Services\WorkingDayCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes de vacaciones de prueba repartidas por el mes en curso y los
 * vecinos, para ver el calendario con casos reales: rangos que cruzan de
 * semana, varias personas fuera el mismo día y los cuatro estatus.
 *
 * Las crea con VacationRequestService, no a mano, para que los días hábiles y
 * el saldo de cada periodo queden como los calcularía el sistema. Es
 * idempotente: si ya hay solicitudes sembradas, no vuelve a crearlas.
 *
 * Solo corre en ambiente local: son datos ficticios.
 */
class VacationRequestSeeder extends Seeder
{
    /**
     * Cada renglón es un rango relativo al primer día del mes en curso, para
     * que el calendario se vea poblado sin importar cuándo se siembre.
     *
     * offset/length van en días naturales desde ese primero; status es el
     * estado final al que se lleva la solicitud.
     *
     * @var list<array{offset: int, length: int, status: string, comments: ?string}>
     */
    private array $plan = [
        // Mes en curso: lo que RH ve al entrar.
        ['offset' => 2, 'length' => 4, 'status' => VacationRequest::APPROVED, 'comments' => 'Viaje familiar'],
        ['offset' => 7, 'length' => 2, 'status' => VacationRequest::APPROVED, 'comments' => null],
        ['offset' => 9, 'length' => 6, 'status' => VacationRequest::APPROVED, 'comments' => 'Boda de su hermana'],
        ['offset' => 13, 'length' => 0, 'status' => VacationRequest::PENDING, 'comments' => 'Trámite personal'],
        ['offset' => 15, 'length' => 8, 'status' => VacationRequest::PENDING, 'comments' => null],
        ['offset' => 18, 'length' => 3, 'status' => VacationRequest::APPROVED, 'comments' => 'Puente'],
        ['offset' => 21, 'length' => 5, 'status' => VacationRequest::REJECTED, 'comments' => 'Cierre de inventario'],
        ['offset' => 24, 'length' => 1, 'status' => VacationRequest::CANCELLED, 'comments' => null],

        // Mes anterior y siguiente: se ven al navegar y prueban los rangos
        // que entran o salen del mes en pantalla.
        ['offset' => -6, 'length' => 9, 'status' => VacationRequest::APPROVED, 'comments' => 'Descanso de fin de proyecto'],
        ['offset' => -14, 'length' => 3, 'status' => VacationRequest::APPROVED, 'comments' => null],
        ['offset' => 28, 'length' => 7, 'status' => VacationRequest::PENDING, 'comments' => 'Vacaciones de temporada'],
        ['offset' => 34, 'length' => 4, 'status' => VacationRequest::APPROVED, 'comments' => null],
    ];

    /** Turno al que se adscribe a quien no tenga uno; sin turno no hay días hábiles. */
    private const FALLBACK_SHIFT = 'REGULAR';

    public function __construct(
        private readonly VacationRequestService $requests,
        private readonly ShiftAssignmentService $shifts,
        private readonly WorkingDayCalculator $workingDays,
    ) {}

    public function run(): void
    {
        if (! App::environment('local')) {
            $this->command->warn('VacationRequestSeeder omitido: solo corre en local.');

            return;
        }

        if (VacationRequest::query()->exists()) {
            $this->command->info('Ya hay solicitudes de vacaciones; no se siembra nada.');

            return;
        }

        $shift = Shift::where('name', self::FALLBACK_SHIFT)->first() ?? Shift::first();

        if ($shift === null) {
            $this->command->warn('VacationRequestSeeder omitido: no hay turnos configurados.');

            return;
        }

        $candidates = $this->candidates(count($this->plan));

        if ($candidates->isEmpty()) {
            $this->command->warn('VacationRequestSeeder omitido: ningún empleado tiene un año de servicio.');

            return;
        }

        $author = User::where('email', 'admin@victorino.com')->first() ?? User::first();
        $firstOfMonth = CarbonImmutable::today()->startOfMonth();

        $created = 0;
        $skipped = 0;

        foreach ($this->plan as $index => $entry) {
            $employee = $candidates->get($index % $candidates->count());

            if ($employee === null) {
                continue;
            }

            $this->ensureShift($employee, $shift);

            // Un rango corto que cae en descanso no consume días y el servicio
            // lo rechaza; correrlo al siguiente día hábil conserva el renglón.
            $from = $this->firstWorkingDay($employee, $firstOfMonth->addDays($entry['offset']));
            $to = $from->addDays($entry['length']);

            try {
                $request = $this->requests->create(
                    $employee,
                    $from,
                    $to,
                    $entry['comments'],
                    $author?->id,
                );
            } catch (ValidationException $e) {
                // Saldo insuficiente, traslape o un rango sin días laborables:
                // el plan es fijo y la plantilla cambia, así que se omite ese
                // renglón en vez de abortar la siembra.
                $this->command->warn(
                    "· {$employee->full_name} ({$from->toDateString()}): "
                    .collect($e->errors())->flatten()->first()
                );
                $skipped++;

                continue;
            }

            $this->applyStatus($request, $entry['status'], $author?->id);
            $created++;
        }

        $this->command->info("{$created} solicitudes de vacaciones sembradas ({$skipped} omitidas).");
    }

    /**
     * Empleados con al menos un año de servicio, repartidos entre áreas para
     * que el filtro del calendario tenga qué mostrar.
     *
     * @return \Illuminate\Support\Collection<int, Employee>
     */
    private function candidates(int $limit): \Illuminate\Support\Collection
    {
        return Employee::query()
            ->whereNotNull('hire_date')
            ->whereDate('hire_date', '<=', CarbonImmutable::today()->subYear()->toDateString())
            ->with('subDepartment')
            ->get()
            // Un empleado por sub área antes de repetir: así las solicitudes no
            // caen todas en la misma rama del organigrama.
            ->groupBy('sub_department_id')
            ->map(fn ($group) => $group->sortBy('hire_date')->values())
            ->pipe(function ($groups) {
                $ordered = collect();
                $round = 0;

                while ($ordered->count() < $groups->flatten()->count()) {
                    foreach ($groups as $group) {
                        if ($group->has($round)) {
                            $ordered->push($group[$round]);
                        }
                    }
                    $round++;
                }

                return $ordered;
            })
            ->take($limit)
            ->values();
    }

    /** Primer día laborable del empleado a partir de la fecha dada. */
    private function firstWorkingDay(Employee $employee, CarbonImmutable $from): CarbonImmutable
    {
        $workingDays = $this->workingDays->between($employee, $from, $from->addDays(9));

        return $workingDays->first() ?? $from;
    }

    /** Sin turno vigente el cálculo de días hábiles da cero y la solicitud se rechaza. */
    private function ensureShift(Employee $employee, Shift $shift): void
    {
        if ($employee->shiftAssignments()->exists()) {
            return;
        }

        $startsOn = CarbonImmutable::today()->subYear()->startOfYear();

        $this->shifts->assign(
            $employee,
            $shift,
            $startsOn->lt($employee->hire_date) ? CarbonImmutable::parse($employee->hire_date) : $startsOn,
            null,
            'Asignado por el seeder de vacaciones.',
            null,
        );
    }

    /** La solicitud nace pendiente; los demás estatus se aplican encima. */
    private function applyStatus(VacationRequest $request, string $status, ?int $authorId): void
    {
        match ($status) {
            VacationRequest::APPROVED => $this->requests->approve($request, $authorId),
            VacationRequest::REJECTED => $this->requests->reject($request, $authorId, 'Cobertura insuficiente en esas fechas.'),
            VacationRequest::CANCELLED => $this->requests->cancel($request, $authorId),
            default => null,
        };
    }
}
