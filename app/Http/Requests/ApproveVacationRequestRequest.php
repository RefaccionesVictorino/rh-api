<?php

namespace App\Http\Requests;

use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Aprobación con fechas opcionales: si vienen y difieren de las pedidas, RH
 * está reprogramando y debe explicar por qué. Sin fechas, o con las mismas,
 * se aprueba tal cual.
 */
class ApproveVacationRequestRequest extends FormRequest
{
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
            'starts_on' => ['nullable', 'required_with:ends_on', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_with:starts_on', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reschedule_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->reschedules()) {
                    return;
                }

                [$from, $to] = $this->range();

                if ($from->diffInDays($to) + 1 > StoreVacationRequestRequest::MAX_DAYS) {
                    $validator->errors()->add('ends_on', 'El periodo no puede exceder '.StoreVacationRequestRequest::MAX_DAYS.' días.');
                }

                if ($from->lessThan(CarbonImmutable::today())) {
                    $validator->errors()->add('starts_on', 'Las vacaciones no pueden iniciar en una fecha pasada.');
                }

                if (trim((string) $this->validated('reschedule_reason')) === '') {
                    $validator->errors()->add('reschedule_reason', 'Explica por qué cambian las fechas.');
                }
            },
        ];
    }

    /** Hay fechas y no son las que pidió el empleado. */
    public function reschedules(): bool
    {
        $range = $this->range();

        if ($range === null) {
            return false;
        }

        /** @var VacationRequest $vacationRequest */
        $vacationRequest = $this->route('vacation_request');

        return $range[0]->toDateString() !== $vacationRequest->starts_on->toDateString()
            || $range[1]->toDateString() !== $vacationRequest->ends_on->toDateString();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function range(): ?array
    {
        $startsOn = $this->validated('starts_on');
        $endsOn = $this->validated('ends_on');

        if ($startsOn === null || $endsOn === null) {
            return null;
        }

        return [
            CarbonImmutable::parse($startsOn)->startOfDay(),
            CarbonImmutable::parse($endsOn)->startOfDay(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
            'reschedule_reason' => 'motivo del cambio',
        ];
    }
}
