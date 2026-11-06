<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesWorkerVacationRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Rango que el trabajador pide desde la app; la sesión ya lo identifica. */
class WorkerVacationRequest extends FormRequest
{
    use ValidatesWorkerVacationRange;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->vacationRangeRules();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateVacationRange($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->vacationRangeAttributes();
    }
}
