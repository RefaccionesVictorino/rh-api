<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\HolidayRule;
use Illuminate\Database\Seeder;

/**
 * Catálogo de días de descanso obligatorio del artículo 74 de la Ley Federal
 * del Trabajo.
 *
 * Se siembran como reglas, no como fechas: valen para cualquier año sin volver
 * a capturar nada. Es idempotente y respeta lo que RH haya ajustado.
 */
class HolidaySeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $rules = [
        ['name' => 'Año Nuevo', 'month' => 1, 'day' => 1],
        ['name' => 'Aniversario de la Constitución', 'month' => 2, 'weekday' => 1, 'week_of_month' => 1],
        ['name' => 'Natalicio de Benito Juárez', 'month' => 3, 'weekday' => 1, 'week_of_month' => 3],
        ['name' => 'Día del Trabajo', 'month' => 5, 'day' => 1],
        ['name' => 'Independencia de México', 'month' => 9, 'day' => 16],
        ['name' => 'Aniversario de la Revolución', 'month' => 11, 'weekday' => 1, 'week_of_month' => 3],
        ['name' => 'Navidad', 'month' => 12, 'day' => 25],
    ];

    public function run(): void
    {
        $created = 0;

        foreach ($this->rules as $rule) {
            $isNthWeekday = isset($rule['weekday']);

            $record = HolidayRule::firstOrCreate(
                ['name' => $rule['name']],
                $rule + [
                    'rule_type' => $isNthWeekday ? HolidayRule::NTH_WEEKDAY : HolidayRule::FIXED,
                    'observance' => HolidayRule::REST,
                    'is_mandatory' => true,
                ],
            );

            $created += $record->wasRecentlyCreated ? 1 : 0;
        }

        $this->command->info("{$created} reglas de días festivos registradas.");

        $this->dropDatesSupersededByRules();
    }

    /**
     * Las fechas que sembró la versión anterior del seeder ahora las genera el
     * catálogo; dejarlas duplicaría el festivo en el calendario.
     */
    private function dropDatesSupersededByRules(): void
    {
        $names = array_column($this->rules, 'name');

        $removed = Holiday::whereNull('holiday_rule_id')
            ->where('is_mandatory', true)
            ->whereIn('name', $names)
            ->delete();

        if ($removed > 0) {
            $this->command->info("{$removed} fechas sembradas antes se reemplazaron por el catálogo.");
        }
    }
}
