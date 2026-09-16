<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\HolidayRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Festivos de un rango de fechas, ya combinados el catálogo anual y las fechas
 * capturadas.
 *
 * Las reglas se evalúan al vuelo para el año consultado: el catálogo nunca
 * caduca ni hay que sembrar nada al cambiar de año. Una fecha capturada sobre
 * la misma regla la sustituye, que es como RH mueve o cancela un festivo en un
 * año concreto.
 */
class HolidayCalendar
{
    /**
     * @return Collection<string, Holiday> Festivos indexados por fecha.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $stored = Holiday::betweenDates($from, $to)
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());

        // Las excepciones de años del rango pisan a su regla aunque la fecha
        // resultante caiga fuera: mover un festivo no debe duplicarlo.
        $overriddenRules = Holiday::whereNotNull('holiday_rule_id')
            ->whereIn('holiday_rule_id', HolidayRule::active()->pluck('id'))
            ->get()
            ->groupBy('holiday_rule_id')
            ->map(fn (Collection $group) => $group
                ->map(fn (Holiday $holiday) => (int) $holiday->date->year)
                ->all());

        $calendar = $stored->all();

        foreach (HolidayRule::active()->get() as $rule) {
            foreach (range((int) $from->year, (int) $to->year) as $year) {
                if (in_array($year, $overriddenRules->get($rule->id, []), true)) {
                    continue;
                }

                $date = $rule->dateFor($year);

                if ($date === null || $date->lt($from) || $date->gt($to)) {
                    continue;
                }

                $key = $date->toDateString();

                if (! isset($calendar[$key])) {
                    $calendar[$key] = $this->asHoliday($rule, $key);
                }
            }
        }

        ksort($calendar);

        return collect($calendar);
    }

    /**
     * @return Collection<string, Holiday>
     */
    public function forYear(int $year): Collection
    {
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();

        return $this->between($from, $from->endOfYear());
    }

    /**
     * La fecha generada viaja como un Holiday sin persistir: quien la consume
     * no tiene que distinguirla de una capturada.
     */
    private function asHoliday(HolidayRule $rule, string $date): Holiday
    {
        $holiday = new Holiday([
            'holiday_rule_id' => $rule->id,
            'date' => $date,
            'name' => $rule->name,
            'observance' => $rule->observance,
            'start_time' => $rule->start_time,
            'end_time' => $rule->end_time,
            'break_start' => $rule->break_start,
            'break_end' => $rule->break_end,
            'is_mandatory' => $rule->is_mandatory,
            'notes' => $rule->notes,
        ]);

        $holiday->setRelation('rule', $rule);

        return $holiday;
    }
}
