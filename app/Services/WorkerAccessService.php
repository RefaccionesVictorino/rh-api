<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkerAccount;
use App\Models\WorkerDevice;
use App\Rules\SecureNip;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Credenciales y sesiones de la app del trabajador. RH genera un NIP temporal
 * desde el panel; el trabajador lo cambia por uno propio en su primer acceso.
 */
class WorkerAccessService
{
    public const TEMPORARY_NIP_HOURS = 72;

    public const SESSION_DAYS = 90;

    public const TOKEN_ABILITY = 'worker';

    /**
     * Genera un NIP temporal y cierra las sesiones abiertas: quien tuviera el
     * anterior ya no debe seguir dentro.
     *
     * @return array{0: WorkerAccount, 1: string} La cuenta y el NIP en claro, que solo se muestra una vez.
     */
    public function issueTemporaryNip(Employee $employee, User $issuer): array
    {
        do {
            $nip = str_pad((string) random_int(0, 999_999), SecureNip::LENGTH, '0', STR_PAD_LEFT);
        } while (SecureNip::isGuessable($nip, $employee->birthdate));

        $account = DB::transaction(function () use ($employee, $issuer, $nip): WorkerAccount {
            $account = WorkerAccount::updateOrCreate(['employee_id' => $employee->id], [
                'nip' => $nip,
                'nip_expires_at' => now()->addHours(self::TEMPORARY_NIP_HOURS),
                'nip_issued_by' => $issuer->id,
            ]);

            $account->revokeSessions();

            return $account;
        });

        return [$account, $nip];
    }

    /** Fija el NIP elegido por el trabajador; deja de ser temporal. */
    public function setNip(WorkerAccount $account, string $nip): void
    {
        $account->update([
            'nip' => $nip,
            'nip_expires_at' => null,
            'activated_at' => $account->activated_at ?? now(),
        ]);
    }

    /**
     * Abre una sesión para un teléfono. Si esa instalación ya tenía sesión
     * (de este trabajador o de otro que usó el mismo teléfono), se cierra.
     *
     * @param  array{installation_id: string, platform: string, name?: string|null, os_version?: string|null, app_version?: string|null}  $device
     */
    public function startSession(WorkerAccount $account, array $device): NewAccessToken
    {
        return DB::transaction(function () use ($account, $device): NewAccessToken {
            WorkerDevice::where('installation_id', $device['installation_id'])
                ->first()
                ?->accessToken()
                ->delete();

            $token = $account->createToken(
                $device['name'] ?? 'app',
                [self::TOKEN_ABILITY],
                now()->addDays(self::SESSION_DAYS),
            );

            $account->devices()->create([
                'personal_access_token_id' => $token->accessToken->id,
                'installation_id' => $device['installation_id'],
                'platform' => $device['platform'],
                'device_name' => $device['name'] ?? null,
                'os_version' => $device['os_version'] ?? null,
                'app_version' => $device['app_version'] ?? null,
            ]);

            $account->update(['last_login_at' => now()]);

            return $token;
        });
    }
}
