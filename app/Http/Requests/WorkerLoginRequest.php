<?php

namespace App\Http\Requests;

use App\Models\WorkerAccount;
use App\Models\WorkerDevice;
use App\Rules\SecureNip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Login de la app con RFC + NIP. El RFC se busca como PIN del checador, igual
 * que en el portal web, así la baja (que desactiva al usuario del checador)
 * también corta el acceso aquí.
 */
class WorkerLoginRequest extends FormRequest
{
    /** Con 6 dígitos y este tope, adivinar un NIP no es práctico. */
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    private ?WorkerAccount $account = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rfc')) {
            $this->merge(['rfc' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('rfc')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rfc' => ['required', 'string', 'max:20'],
            'nip' => ['required', 'string', 'max:'.SecureNip::LENGTH],
            // Solo se toma en cuenta cuando el NIP actual es temporal.
            'new_nip' => ['nullable', 'string'],
            'device' => ['required', 'array'],
            'device.installation_id' => ['required', 'string', 'max:64'],
            'device.platform' => ['required', Rule::in(WorkerDevice::PLATFORMS)],
            'device.name' => ['nullable', 'string', 'max:100'],
            'device.os_version' => ['nullable', 'string', 'max:30'],
            'device.app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $attemptsKey = 'worker-login|'.$this->input('rfc');

                if (RateLimiter::tooManyAttempts($attemptsKey, self::MAX_FAILED_ATTEMPTS)) {
                    $validator->errors()->add('rfc', sprintf(
                        'Demasiados intentos fallidos. Vuelve a intentarlo en %d minutos o acude a RH.',
                        (int) ceil(RateLimiter::availableIn($attemptsKey) / 60),
                    ));

                    return;
                }

                $account = WorkerAccount::query()
                    ->whereHas('employee.timeClockUser', fn ($query) => $query
                        ->where('pin', $this->input('rfc'))
                        ->where('is_active', true))
                    ->with('employee')
                    ->first();

                // Un solo mensaje para RFC inexistente y NIP incorrecto:
                // distinguirlos permitiría averiguar qué RFC tienen cuenta.
                if ($account === null || ! Hash::check($this->input('nip'), $account->nip)) {
                    RateLimiter::hit($attemptsKey, self::LOCKOUT_SECONDS);
                    $validator->errors()->add('rfc', 'RFC o NIP incorrectos.');

                    return;
                }

                RateLimiter::clear($attemptsKey);

                if ($account->temporaryNipExpired()) {
                    $validator->errors()->add('nip', 'Tu NIP temporal venció. Pide uno nuevo a Recursos Humanos.');

                    return;
                }

                if ($account->hasTemporaryNip() && $this->filled('new_nip')) {
                    $this->validateNewNip($validator, $account);
                }

                $this->account = $account;
            },
        ];
    }

    private function validateNewNip(Validator $validator, WorkerAccount $account): void
    {
        $newNip = (string) $this->input('new_nip');

        (new SecureNip($account->employee->birthdate))->validate(
            'new_nip',
            $newNip,
            fn (string $message) => $validator->errors()->add('new_nip', $message),
        );

        if ($newNip === $this->input('nip')) {
            $validator->errors()->add('new_nip', 'El NIP nuevo debe ser distinto del temporal.');
        }
    }

    public function account(): WorkerAccount
    {
        return $this->account;
    }

    /**
     * @return array{installation_id: string, platform: string, name?: string|null, os_version?: string|null, app_version?: string|null}
     */
    public function device(): array
    {
        return $this->validated('device');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rfc' => 'RFC',
            'nip' => 'NIP',
            'new_nip' => 'NIP nuevo',
            'device.installation_id' => 'identificador de la instalación',
            'device.platform' => 'plataforma',
        ];
    }
}
