<?php

namespace App\Http\Requests;

use App\Models\VacationPeriod;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Aprobación con fechas opcionales: si vienen y difieren de las pedidas, RH
 * está reprogramando y debe explicar por qué. Sin fechas, o con las mismas,
 * se aprueba tal cual.
 *
 * Puede traer además el pago de la prima vacacional del periodo, que suele
 * hacerse con la primera solicitud aprobada. Es opcional y exige su permiso.
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
            ...PayVacationPremiumRequest::premiumRules(required: false),
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validatePremium($validator);

                if (! $this->reschedules()) {
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

    /** Monto y fecha van juntos, y solo quien puede pagar primas los manda. */
    private function validatePremium(Validator $validator): void
    {
        $hasAmount = $this->validated('premium_amount') !== null;
        $hasDate = $this->validated('premium_paid_on') !== null;

        if (! $hasAmount && ! $hasDate) {
            return;
        }

        if (! $hasAmount) {
            $validator->errors()->add('premium_amount', 'Captura el monto de la prima.');
        }

        if (! $hasDate) {
            $validator->errors()->add('premium_paid_on', 'Captura la fecha de pago de la prima.');
        }

        if (! $this->user()?->can('vacaciones.pagar_prima')) {
            $validator->errors()->add('premium_amount', 'Tu cuenta no tiene permiso para registrar el pago de la prima.');
        }

        if ($this->premiumPeriod()?->is_premium_paid) {
            $validator->errors()->add('premium_amount', 'La prima de este periodo ya está pagada; corrígela desde el periodo.');
        }
    }

    public function paysPremium(): bool
    {
        return $this->validated('premium_amount') !== null;
    }

    /**
     * Periodo al que se abona la prima: el que cubre la fecha con la que se
     * aprueba. Nulo si aún no cumple el año (sin periodo generado).
     */
    public function premiumPeriod(): ?VacationPeriod
    {
        /** @var VacationRequest $vacationRequest */
        $vacationRequest = $this->route('vacation_request');
        $startsOn = $this->range()[0] ?? $vacationRequest->starts_on;

        return $vacationRequest->employee->vacationPeriods()
            ->whereDate('starts_on', '<=', $startsOn->toDateString())
            ->whereDate('ends_on', '>=', $startsOn->toDateString())
            ->first();
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
            ...PayVacationPremiumRequest::premiumAttributes(),
        ];
    }
}
