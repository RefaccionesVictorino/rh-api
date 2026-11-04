<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pago de la prima vacacional de un periodo. El monto es libre: el sistema
 * no conoce el sueldo y RH lo calcula fuera. Sin porcentaje se usa el global.
 */
class PayVacationPremiumRequest extends FormRequest
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
        return self::premiumRules(required: true);
    }

    /**
     * Mismas reglas cuando la prima viaja dentro de la aprobación de una
     * solicitud, donde es opcional.
     *
     * @return array<string, mixed>
     */
    public static function premiumRules(bool $required, string $prefix = ''): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            "{$prefix}premium_amount" => [$presence, 'numeric', 'min:0.01', 'max:99999999.99'],
            "{$prefix}premium_paid_on" => [$presence, 'date_format:Y-m-d', 'before_or_equal:today'],
            "{$prefix}premium_percentage" => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function premiumAttributes(): array
    {
        return [
            'premium_amount' => 'monto de la prima',
            'premium_paid_on' => 'fecha de pago',
            'premium_percentage' => 'porcentaje de la prima',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::premiumAttributes();
    }
}
