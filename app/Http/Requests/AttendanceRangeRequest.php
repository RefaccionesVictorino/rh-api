<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Rango del calendario de asistencia: un mes (`month=YYYY-MM`) o un par de
 * fechas. Sin parámetros se toma el mes en curso.
 */
class AttendanceRangeRequest extends FormRequest
{
    /** Tope para que un rango grande no recorra meses de checadas. */
    public const MAX_DAYS = 93;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                $v->errors()->add('to', 'El rango no puede exceder '.self::MAX_DAYS.' días.');
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $timezone = config('time_clock.timezone');

        if ($this->filled('from') && $this->filled('to')) {
            return [
                CarbonImmutable::parse($this->input('from'), $timezone)->startOfDay(),
                CarbonImmutable::parse($this->input('to'), $timezone)->startOfDay(),
            ];
        }

        $month = $this->filled('month')
            ? CarbonImmutable::createFromFormat('Y-m', $this->input('month'), $timezone)
            : CarbonImmutable::now($timezone);

        return [$month->startOfMonth()->startOfDay(), $month->endOfMonth()->startOfDay()];
    }
}
