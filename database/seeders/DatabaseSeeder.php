<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            // Catálogo, no datos de prueba: corre en todos los ambientes.
            DepartmentSeeder::class,
            LocationSeeder::class,
        ]);

        // Datos de prueba: el seeder se omite solo fuera de local.
        $this->call(EmployeesTableSeeder::class);

        // Ubica esa plantilla en el organigrama; depende de los dos anteriores.
        $this->call(OrgChartAssignmentSeeder::class);
    }
}
