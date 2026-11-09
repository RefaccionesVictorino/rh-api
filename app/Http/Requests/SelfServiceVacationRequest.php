<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesWorkerVacationRange;
use Illuminate\Validation\Validator;

/** Rango que el trabajador pide desde el portal web, identificado en cada petición. */
class SelfServiceVacationRequest extends IdentifyEmployeeRequest
{
    use ValidatesWorkerVacationRange;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), ...$this->vacationRangeRules()];
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->validateVacationRange($validator),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), ...$this->vacationRangeAttributes()];
    }
}
