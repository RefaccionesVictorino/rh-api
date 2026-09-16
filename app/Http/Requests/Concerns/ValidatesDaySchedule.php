<?php

namespace App\Http\Requests\Concerns;

use App\Models\ShiftDay;
use Illuminate\Validation\Validator;

/**
 * Coherencia entre las horas de un día: la comida dentro de la jornada y
 * ambos extremos presentes o ninguno.
 */
trait ValidatesDaySchedule
{
    /**
     * @return array<string, mixed>
     */
    protected function hourRules(string $prefix = ''): array
    {
        return [
            $prefix.'start_time' => ['nullable', 'date_format:H:i'],
            $prefix.'end_time' => ['nullable', 'date_format:H:i'],
            $prefix.'break_start' => ['nullable', 'date_format:H:i'],
            $prefix.'break_end' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function hourAttributes(): array
    {
        return [
            'start_time' => 'hora de entrada',
            'end_time' => 'hora de salida',
            'break_start' => 'inicio de comida',
            'break_end' => 'fin de comida',
        ];
    }

    /**
     * @param  array<string, mixed>  $hours
     */
    protected function validateHours(Validator $validator, array $hours, bool $required): void
    {
        $start = ShiftDay::toMinutes($hours['start_time'] ?? null);
        $end = ShiftDay::toMinutes($hours['end_time'] ?? null);

        if (! $required && $start === null && $end === null) {
            return;
        }

        if ($start === null) {
            $validator->errors()->add('start_time', 'La hora de entrada es obligatoria.');
        }

        if ($end === null) {
            $validator->errors()->add('end_time', 'La hora de salida es obligatoria.');
        }

        if ($start === null || $end === null) {
            return;
        }

        if ($start === $end) {
            $validator->errors()->add('end_time', 'La hora de salida no puede ser igual a la de entrada.');

            return;
        }

        if ($end < $start) {
            $end += ShiftDay::MINUTES_PER_DAY;
        }

        $breakStart = ShiftDay::toMinutes($hours['break_start'] ?? null);
        $breakEnd = ShiftDay::toMinutes($hours['break_end'] ?? null);

        if ($breakStart === null && $breakEnd === null) {
            return;
        }

        if ($breakStart === null || $breakEnd === null) {
            $field = $breakStart === null ? 'break_start' : 'break_end';
            $validator->errors()->add($field, 'La comida necesita hora de inicio y de fin, o ninguna de las dos.');

            return;
        }

        if ($breakStart < $start) {
            $breakStart += ShiftDay::MINUTES_PER_DAY;
        }

        if ($breakEnd <= $breakStart) {
            $breakEnd += ShiftDay::MINUTES_PER_DAY;
        }

        if ($breakStart < $start || $breakEnd > $end) {
            $validator->errors()->add('break_start', 'La comida debe quedar dentro del horario.');

            return;
        }

        if ($breakEnd - $breakStart >= $end - $start) {
            $validator->errors()->add('break_end', 'La comida no puede durar toda la jornada.');
        }
    }
}
