<?php

namespace App\Http\Requests\Concerns;

use App\Models\ShiftDay;
use Illuminate\Validation\Validator;

/**
 * Reglas del horario semanal, compartidas entre alta y edición de turnos.
 *
 * Las reglas declarativas solo cuidan el formato; la coherencia entre horas
 * (comida dentro de la jornada, turnos que cruzan medianoche, al menos un día
 * laborable) se revisa a mano porque depende de varios campos a la vez.
 */
trait ValidatesShiftDays
{
    /**
     * @return array<string, mixed>
     */
    protected function dayRules(): array
    {
        return [
            'days' => ['array', 'max:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.is_rest_day' => ['nullable', 'boolean'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'days.*.break_start' => ['nullable', 'date_format:H:i'],
            'days.*.break_end' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function dayAttributes(): array
    {
        return [
            'days' => 'días',
            'days.*.weekday' => 'día de la semana',
            'days.*.is_rest_day' => 'descanso',
            'days.*.start_time' => 'hora de entrada',
            'days.*.end_time' => 'hora de salida',
            'days.*.break_start' => 'inicio de comida',
            'days.*.break_end' => 'fin de comida',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function dayMessages(): array
    {
        return [
            'days.*.weekday.distinct' => 'El día de la semana está repetido.',
        ];
    }

    protected function validateDays(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || ! $this->has('days')) {
            return;
        }

        $days = $this->input('days', []);
        $workingDays = 0;

        foreach ($days as $index => $day) {
            if ((bool) ($day['is_rest_day'] ?? false)) {
                continue;
            }

            $workingDays++;
            $this->validateWorkingDay($validator, "days.{$index}", $day);
        }

        if ($workingDays === 0) {
            $validator->errors()->add('days', 'El turno debe tener al menos un día laborable.');
        }
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function validateWorkingDay(Validator $validator, string $key, array $day): void
    {
        $start = ShiftDay::toMinutes($day['start_time'] ?? null);
        $end = ShiftDay::toMinutes($day['end_time'] ?? null);

        if ($start === null) {
            $validator->errors()->add("{$key}.start_time", 'La hora de entrada es obligatoria en un día laborable.');
        }

        if ($end === null) {
            $validator->errors()->add("{$key}.end_time", 'La hora de salida es obligatoria en un día laborable.');
        }

        if ($start === null || $end === null) {
            return;
        }

        if ($start === $end) {
            $validator->errors()->add("{$key}.end_time", 'La hora de salida no puede ser igual a la de entrada.');

            return;
        }

        // Salida menor a entrada = el turno cruza medianoche.
        if ($end < $start) {
            $end += ShiftDay::MINUTES_PER_DAY;
        }

        $breakStart = ShiftDay::toMinutes($day['break_start'] ?? null);
        $breakEnd = ShiftDay::toMinutes($day['break_end'] ?? null);

        // Turno corrido: sin comida.
        if ($breakStart === null && $breakEnd === null) {
            return;
        }

        if ($breakStart === null || $breakEnd === null) {
            $field = $breakStart === null ? 'break_start' : 'break_end';
            $validator->errors()->add("{$key}.{$field}", 'La comida necesita hora de inicio y de fin, o ninguna de las dos para turno corrido.');

            return;
        }

        // Mismo eje que la jornada: una comida después de medianoche en un
        // turno nocturno se mide por encima de 1440.
        if ($breakStart < $start) {
            $breakStart += ShiftDay::MINUTES_PER_DAY;
        }

        if ($breakEnd <= $breakStart) {
            $breakEnd += ShiftDay::MINUTES_PER_DAY;
        }

        if ($breakStart < $start || $breakEnd > $end) {
            $validator->errors()->add("{$key}.break_start", 'La comida debe quedar dentro del horario del turno.');

            return;
        }

        if ($breakEnd - $breakStart >= $end - $start) {
            $validator->errors()->add("{$key}.break_end", 'La comida no puede durar toda la jornada.');
        }
    }
}
