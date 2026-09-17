<?php

namespace Database\Seeders;

use App\Models\VacationEntitlement;
use Illuminate\Database\Seeder;

/**
 * Tabulador del artículo 76 de la Ley Federal del Trabajo, con la reforma
 * vigente desde el 1 de enero de 2023.
 *
 * Del sexto año en adelante suben dos días por cada cinco años de servicio.
 */
class VacationEntitlementSeeder extends Seeder
{
    /** Hasta dónde se materializa la progresión de cinco en cinco. */
    private const MAX_YEAR = 50;

    public function run(): void
    {
        $rows = [
            ['from_year' => 1, 'to_year' => 1, 'days' => 12],
            ['from_year' => 2, 'to_year' => 2, 'days' => 14],
            ['from_year' => 3, 'to_year' => 3, 'days' => 16],
            ['from_year' => 4, 'to_year' => 4, 'days' => 18],
            ['from_year' => 5, 'to_year' => 5, 'days' => 20],
        ];

        $days = 22;

        for ($from = 6; $from <= self::MAX_YEAR; $from += 5) {
            $to = $from + 4;

            $rows[] = [
                'from_year' => $from,
                // El último renglón queda abierto para que ninguna antigüedad
                // se salga del tabulador.
                'to_year' => $to >= self::MAX_YEAR ? null : $to,
                'days' => $days,
            ];

            $days += 2;
        }

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
