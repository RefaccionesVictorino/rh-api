<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\TimeClockUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Validator;

/**
 * Identificación del portal del trabajador, que no tiene sesión: el PIN con el
 * que checa más su fecha de nacimiento viajan en cada petición. El PIN es el
 * RFC (ver EmployeeRegistrationService), así que el portal lo pide como RFC.
 */
class IdentifyEmployeeRequest extends FormRequest
{
    /**
     * La fecha de nacimiento es lo único que protege cada RFC: con este
     * tope, recorrer las fechas posibles de un trabajador tomaría años.
     */
    private const MAX_FAILED_ATTEMPTS = 10;

    private const LOCKOUT_SECONDS = 3600;

    private ?Employee $employee = null;

    public function authorize(): bool
    {
        return true;
    }

    /** Mismo formato con el que el alta guarda el RFC y, con él, el PIN. */
    protected function prepareForValidation(): void
    {
        if ($this->has('pin')) {
            $this->merge(['pin' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('pin')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'max:20'],
            'birthdate' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $pin = $this->input('pin');
                $attemptsKey = 'self-service-identify|'.$pin;

                if (RateLimiter::tooManyAttempts($attemptsKey, self::MAX_FAILED_ATTEMPTS)) {
                    $validator->errors()->add('pin', sprintf(
                        'Demasiados intentos fallidos. Vuelve a intentarlo en %d minutos o acude a RH.',
                        (int) ceil(RateLimiter::availableIn($attemptsKey) / 60),
                    ));

                    return;
                }

                $this->employee = TimeClockUser::query()
                    ->where('pin', $pin)
                    ->where('is_active', true)
                    ->first()
                    ?->employee()
                    ->whereDate('birthdate', $this->input('birthdate'))
                    ->first();

                // Un solo mensaje para ambos casos: distinguirlos permitiría
                // averiguar qué RFC están registrados.
                if ($this->employee === null) {
                    RateLimiter::hit($attemptsKey, self::LOCKOUT_SECONDS);

                    $validator->errors()->add(
                        'pin',
                        'No encontramos a un trabajador con ese RFC y fecha de nacimiento.',
                    );
                }
            },
        ];
    }

    public function employee(): Employee
    {
        return $this->employee;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pin' => 'RFC',
            'birthdate' => 'fecha de nacimiento',
        ];
    }
}
