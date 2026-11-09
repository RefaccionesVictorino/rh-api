<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\StoreVacationRequestRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * Reglas del rango que pide el propio trabajador, en el portal web y en la
 * app. A diferencia de la captura de RH, aquí sí aplica la anticipación
 * mínima: RH puede registrar vacaciones de último momento, el trabajador no.
 */
trait ValidatesWorkerVacationRange
{
    public const MIN_NOTICE_MONTHS = 1;

    /** Primer día que el trabajador puede pedir hoy. */
    public static function earliestStartDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addMonthsNoOverflow(self::MIN_NOTICE_MONTHS);
    }

    /**
     * @return array<string, mixed>
     */
    protected function vacationRangeRules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'comments' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function validateVacationRange(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        [$from, $to] = $this->range();

        if ($from->diffInDays($to) + 1 > StoreVacationRequestRequest::MAX_DAYS) {
            $validator->errors()->add(
                'ends_on',
                'El periodo no puede exceder '.StoreVacationRequestRequest::MAX_DAYS.' días.',
            );
        }

        $earliest = self::earliestStartDate();

        if ($from->lessThan($earliest)) {
            $validator->errors()->add('starts_on', sprintf(
                'Las vacaciones se solicitan con al menos un mes de anticipación: la fecha más próxima es el %s.',
                $earliest->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            ));
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        return [
            CarbonImmutable::parse($this->validated('starts_on'))->startOfDay(),
            CarbonImmutable::parse($this->validated('ends_on'))->startOfDay(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function vacationRangeAttributes(): array
    {
        return [
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
            'comments' => 'comentarios',
        ];
    }
}
