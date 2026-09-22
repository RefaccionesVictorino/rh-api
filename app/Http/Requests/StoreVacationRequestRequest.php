<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreVacationRequestRequest extends FormRequest
{
    /** Tope de rango; una solicitud más larga que esto es un error de captura. */
    public const MAX_DAYS = 120;

    /**
     * Antelación mínima con la que se puede pedir un periodo. El área necesita
     * el margen para acomodar la cobertura de quien sale.
     */
    public const MIN_NOTICE_MONTHS = 1;

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
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'comments' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                [$from, $to] = $this->range();

                if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('ends_on', 'El periodo no puede exceder '.self::MAX_DAYS.' días.');
                }

                $earliest = self::earliestStartDate();

                if ($from->lessThan($earliest)) {
                    $validator->errors()->add(
                        'starts_on',
                        'Las vacaciones se solicitan con al menos un mes de antelación: la fecha más próxima es el '
                            .$earliest->toDateString().'.',
                    );
                }
            },
        ];
    }

    /** Primera fecha de inicio que respeta la antelación mínima. */
    public static function earliestStartDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addMonths(self::MIN_NOTICE_MONTHS);
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
    public function attributes(): array
    {
        return [
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
            'comments' => 'comentarios',
        ];
    }
}
