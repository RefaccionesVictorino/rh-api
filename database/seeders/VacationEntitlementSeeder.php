<?php

namespace Database\Seeders;

use App\Models\VacationEntitlement;
use Illuminate\Database\Seeder;

/**
 * Tabulador del artículo 76 de la Ley Federal del Trabajo, con la reforma
 * vigente desde el 1 de enero de 2023.
 *
 * Del sexto año en adelante suben dos días por cada cinco años de servicio,
 * hasta los 32 días del renglón de 31 en adelante. La ley no sigue después de
 * ese tope: el último renglón queda abierto en 32 y no se extrapola.
 */
class VacationEntitlementSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['from_year' => 1, 'to_year' => 1, 'days' => 12],
            ['from_year' => 2, 'to_year' => 2, 'days' => 14],
            ['from_year' => 3, 'to_year' => 3, 'days' => 16],
            ['from_year' => 4, 'to_year' => 4, 'days' => 18],
            ['from_year' => 5, 'to_year' => 5, 'days' => 20],
            ['from_year' => 6, 'to_year' => 10, 'days' => 22],
            ['from_year' => 11, 'to_year' => 15, 'days' => 24],
            ['from_year' => 16, 'to_year' => 20, 'days' => 26],
            ['from_year' => 21, 'to_year' => 25, 'days' => 28],
            ['from_year' => 26, 'to_year' => 30, 'days' => 30],
            ['from_year' => 31, 'to_year' => null, 'days' => 32],
        ];

        $created = 0;

        foreach ($rows as $row) {
            $created += VacationEntitlement::firstOrCreate(
                ['from_year' => $row['from_year']],
                $row,
            )->wasRecentlyCreated ? 1 : 0;
        }

        $this->command->info("{$created} renglones del tabulador de vacaciones registrados.");
    }
}
