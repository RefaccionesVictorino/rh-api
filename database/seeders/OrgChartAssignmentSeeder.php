<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SubDepartment;
use Illuminate\Database\Seeder;

/**
 * Reparte la plantilla entre las sub áreas y nombra responsables.
 *
 * Es un seeder de datos de prueba, no de catálogo: solo tiene sentido sobre la
 * plantilla ficticia que siembra EmployeesTableSeeder. Es idempotente y
 * conservador: no toca a quien ya tiene sub área ni a las áreas que ya tienen
 * responsable, de modo que puede volver a correrse sin deshacer lo que RH haya
 * ajustado a mano.
 */
class OrgChartAssignmentSeeder extends Seeder
{
    /**
     * Peso relativo de cada área al repartir. Almacén y Ventas concentran la
     * mayor parte de la plantilla de una refaccionaria; Dirección, la menor.
     */
    private array $pesos = [
        'Dirección General' => 1,
        'Recursos Humanos' => 2,
        'Contabilidad y Finanzas' => 3,
        'Ventas' => 6,
        'Almacén' => 5,
        'Compras' => 2,
        'Sistemas' => 2,
        'Logística' => 4,
    ];

    public function run(): void
    {
        $subDepartments = SubDepartment::with('department')->get();

        if ($subDepartments->isEmpty()) {
            $this->command->warn('No hay sub áreas; corre antes DepartmentSeeder.');

            return;
        }

        // Solo se reparte a quien no tenga ubicación todavía.
        $sinAsignar = Employee::whereNull('sub_department_id')->get();

        if ($sinAsignar->isNotEmpty()) {
            $this->repartir($sinAsignar, $subDepartments);
        }

        $this->nombrarResponsables($subDepartments);

        $this->command->info(sprintf(
            '%d empleados ubicados; %d áreas y %d sub áreas con responsable.',
            Employee::whereNotNull('sub_department_id')->count(),
            Department::whereNotNull('manager_id')->count(),
            SubDepartment::whereNotNull('manager_id')->count(),
        ));
    }

    /**
     * Distribuye a los empleados entre las sub áreas, proporcionalmente al peso
     * del área a la que pertenecen.
     */
    private function repartir($empleados, $subDepartments): void
    {
        // Una entrada por sub área repetida tantas veces como su peso: sortear
        // sobre esta lista da la distribución deseada sin cálculo de cuotas.
        $urna = [];
        foreach ($subDepartments as $sub) {
            $peso = $this->pesos[$sub->department?->name] ?? 2;
            for ($i = 0; $i < $peso; $i++) {
                $urna[] = $sub->id;
            }
        }

        foreach ($empleados as $index => $empleado) {
            // Recorrido cíclico en vez de aleatorio: el reparto es reproducible
            // y ninguna sub área queda vacía por azar.
            $empleado->update([
                'sub_department_id' => $urna[$index % count($urna)],
            ]);
        }
    }

    /**
     * Nombra responsable de cada sub área, y de cada área a uno de los jefes de
     * sus sub áreas: el jefe de área sale de dentro del área, no de fuera.
     */
    private function nombrarResponsables($subDepartments): void
    {
        foreach ($subDepartments as $sub) {
            if ($sub->manager_id !== null) {
                continue;
            }

            // El de mayor antigüedad entre los suyos: criterio estable y con
            // sentido, en vez de uno al azar.
            $jefe = Employee::where('sub_department_id', $sub->id)
                ->orderBy('hire_date')
                ->orderBy('id')
                ->first();

            if ($jefe !== null) {
                $sub->update(['manager_id' => $jefe->id]);
            }
        }

        foreach (Department::whereNull('manager_id')->get() as $area) {
            $jefeDeSub = SubDepartment::where('department_id', $area->id)
                ->whereNotNull('manager_id')
                ->orderBy('name')
                ->first();

            if ($jefeDeSub !== null) {
                $area->update(['manager_id' => $jefeDeSub->manager_id]);
            }
        }
    }
}
