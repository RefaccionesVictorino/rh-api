<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mantiene sin traslapes las vigencias de turno de cada empleado.
 *
 * La regla de negocio es que en cualquier fecha un empleado tiene a lo más un
 * turno. Los casos habituales de RH se resuelven solos: cambiar de turno a
 * partir de una fecha cierra el anterior el día previo, y un cambio temporal
 * (con fecha de fin) reanuda el turno original al terminar. Cualquier otro
 * traslape se rechaza para que RH lo resuelva a mano.
 */
class ShiftAssignmentService
{
    public function assign(
        Employee $employee,
        Shift $shift,
        CarbonInterface $startsOn,
        ?CarbonInterface $endsOn,
        ?string $notes,
        ?int $assignedBy,
    ): EmployeeShift {
        return DB::transaction(function () use ($employee, $shift, $startsOn, $endsOn, $notes, $assignedBy): EmployeeShift {
            $this->guardHireDate($employee, $startsOn);

            $overlapping = $employee->shiftAssignments()
                ->with('shift')
                ->overlapping($startsOn, $endsOn)
                ->orderBy('starts_on')
                ->lockForUpdate()
                ->get();

            $resumeAfter = null;

            foreach ($overlapping as $existing) {
                $isOpenAndOlder = $existing->ends_on === null && $existing->starts_on->lt($startsOn);

                if (! $isOpenAndOlder) {
                    throw $this->overlapError($existing);
                }

                // Cambio de turno: el anterior termina el día previo.
                $existing->update(['ends_on' => $startsOn->copy()->subDay()]);

                // Cambio temporal: el turno anterior se reanuda al terminar.
                if ($endsOn !== null) {
                    $resumeAfter = $existing;
                }
            }

            $assignment = $employee->shiftAssignments()->create([
                'shift_id' => $shift->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'notes' => $notes,
                'assigned_by' => $assignedBy,
            ]);

            if ($resumeAfter !== null) {
                $employee->shiftAssignments()->create([
                    'shift_id' => $resumeAfter->shift_id,
                    'starts_on' => $endsOn->copy()->addDay(),
                    'ends_on' => null,
                    'notes' => "Continúa {$resumeAfter->shift->name} al terminar el cambio temporal.",
                    'assigned_by' => $assignedBy,
                ]);
            }

            return $assignment;
        });
    }

    /**
     * Asigna el mismo turno a varios empleados. Todo o nada: si uno falla,
     * ningún otro queda asignado, y el error indica cuál fue.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, EmployeeShift>
     */
    public function assignMany(
        Shift $shift,
        array $employeeIds,
        CarbonInterface $startsOn,
        ?CarbonInterface $endsOn,
        ?string $notes,
        ?int $assignedBy,
    ): Collection {
        return DB::transaction(function () use ($shift, $employeeIds, $startsOn, $endsOn, $notes, $assignedBy): Collection {
            $employees = Employee::whereIn('id', $employeeIds)->get()->keyBy('id');

            $assignments = collect();
            $errors = [];

            foreach ($employeeIds as $index => $id) {
                try {
                    $assignments->push($this->assign($employees[$id], $shift, $startsOn, $endsOn, $notes, $assignedBy));
                } catch (ValidationException $e) {
                    $message = collect($e->errors())->flatten()->first();
                    $errors["employee_ids.{$index}"] = "{$employees[$id]->full_name}: {$message}";
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $assignments;
        });
    }

    /**
     * Corrige una asignación existente. Aquí no se ajusta nada en automático:
     * cualquier traslape con otra asignación del empleado se rechaza.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(EmployeeShift $assignment, array $data): EmployeeShift
    {
        return DB::transaction(function () use ($assignment, $data): EmployeeShift {
            $assignment->fill($data);

            $this->guardHireDate($assignment->employee, $assignment->starts_on);

            $conflict = $assignment->employee->shiftAssignments()
                ->with('shift')
                ->whereKeyNot($assignment->id)
                ->overlapping($assignment->starts_on, $assignment->ends_on)
                ->orderBy('starts_on')
                ->first();

            if ($conflict !== null) {
                throw $this->overlapError($conflict);
            }

            $assignment->save();

            return $assignment;
        });
    }

    private function guardHireDate(Employee $employee, CarbonInterface $startsOn): void
    {
        if ($employee->hire_date !== null && $startsOn->lt($employee->hire_date)) {
            throw ValidationException::withMessages([
                'starts_on' => 'La asignación no puede empezar antes de la fecha de ingreso ('
                    .$employee->hire_date->toDateString().').',
            ]);
        }
    }

    private function overlapError(EmployeeShift $existing): ValidationException
    {
        $until = $existing->ends_on?->toDateString() ?? 'indefinido';

        return ValidationException::withMessages([
            'starts_on' => "Se traslapa con la asignación al turno {$existing->shift->name} "
                ."del {$existing->starts_on->toDateString()} al {$until}.",
        ]);
    }
}
