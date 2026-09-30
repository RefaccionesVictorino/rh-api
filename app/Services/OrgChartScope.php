<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Trabajadores sobre los que puede operar un usuario.
 *
 * El organigrama no liga trabajadores entre sí: un trabajador queda por encima
 * de otros al ser responsable de un área o de una sub área. Su alcance es todo
 * lo que cuelga de esos nodos, a cualquier profundidad: los empleados ubicados
 * en esas sub áreas y también sus responsables, que en el árbol cuelgan de él
 * aunque su expediente esté ubicado en otra parte. Nunca se incluye a sí mismo.
 */
class OrgChartScope
{
    public function isUnrestricted(User $user): bool
    {
        return $user->employee_id === null || $user->hasAnyRole(User::UNSCOPED_ROLES);
    }

    public function covers(User $user, Employee $employee): bool
    {
        return $this->outOfScope($user, collect([$employee]))->isEmpty();
    }

    /**
     * Los que quedan fuera del alcance. Recibe la lista completa para recorrer
     * el organigrama una sola vez aunque se revisen cientos de trabajadores.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    public function outOfScope(User $user, Collection $employees): Collection
    {
        if ($this->isUnrestricted($user)) {
            return collect();
        }

        $ownId = (int) $user->employee_id;
        $subDepartmentIds = array_flip($this->managedSubDepartmentIds($ownId));
        $managerIds = array_flip($this->managerIdsOf(array_keys($subDepartmentIds)));

        return $employees
            ->filter(fn (Employee $employee): bool => $employee->id === $ownId
                || (! isset($managerIds[$employee->id])
                    && ($employee->sub_department_id === null
                        || ! isset($subDepartmentIds[$employee->sub_department_id]))))
            ->values();
    }

    /**
     * Limita una consulta de empleados al alcance del usuario; sin recorte
     * para quien no lo tiene.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function constrain(Builder $query, User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $ownId = (int) $user->employee_id;
        $subDepartmentIds = $this->managedSubDepartmentIds($ownId);
        $managerIds = $this->managerIdsOf($subDepartmentIds);

        return $query
            ->where(fn (Builder $q) => $q
                ->whereIn('sub_department_id', $subDepartmentIds)
                ->orWhereIn('id', $managerIds))
            ->whereKeyNot($ownId);
    }

    /**
     * Sub áreas que dependen del trabajador: todas las de las áreas que
     * encabeza y, de cada sub área que encabeza, ella y su descendencia.
     *
     * @return list<int>
     */
    public function managedSubDepartmentIds(int $employeeId): array
    {
        $fromDepartments = SubDepartment::query()
            ->whereIn('department_id', Department::where('manager_id', $employeeId)->select('id'))
            ->pluck('id')
            ->all();

        $fromSubDepartments = SubDepartment::where('manager_id', $employeeId)
            ->pluck('id')
            ->flatMap(fn (int $id): array => SubDepartment::descendantIds($id))
            ->all();

        return array_values(array_unique([...$fromDepartments, ...$fromSubDepartments]));
    }

    /**
     * Responsables de esas sub áreas.
     *
     * @param  list<int>  $subDepartmentIds
     * @return list<int>
     */
    private function managerIdsOf(array $subDepartmentIds): array
    {
        return SubDepartment::whereIn('id', $subDepartmentIds)
            ->whereNotNull('manager_id')
            ->distinct()
            ->pluck('manager_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
