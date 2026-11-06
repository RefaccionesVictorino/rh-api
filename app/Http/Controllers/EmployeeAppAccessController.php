<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\WorkerAccount;
use App\Models\WorkerDevice;
use App\Services\WorkerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acceso del trabajador a la app móvil, administrado por RH desde su ficha:
 * estado, sesiones abiertas, NIP temporal y cierre de sesiones.
 */
class EmployeeAppAccessController extends Controller
{
    public const STATUS_NONE = 'none';

    public const STATUS_TEMPORARY = 'temporary';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ACTIVE = 'active';

    public function show(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->overview($employee->workerAccount)]);
    }

    /** El NIP en claro solo viaja en esta respuesta; después no se puede consultar. */
    public function issueNip(Request $request, Employee $employee, WorkerAccessService $access): JsonResponse
    {
        if (! $employee->timeClockUser?->is_active) {
            return response()->json([
                'message' => 'El trabajador no tiene un usuario activo en el checador; sin él no puede entrar a la app.',
            ], 422);
        }

        [$account, $nip] = $access->issueTemporaryNip($employee, $request->user());

        return response()->json([
            'data' => $this->overview($account),
            'nip' => $nip,
            'message' => sprintf(
                'Entrega este NIP al trabajador. Vence en %d horas y no se volverá a mostrar.',
                WorkerAccessService::TEMPORARY_NIP_HOURS,
            ),
        ], 201);
    }

    public function revokeSessions(Employee $employee): JsonResponse
    {
        $employee->workerAccount?->revokeSessions();

        return response()->json([
            'data' => $this->overview($employee->workerAccount),
            'message' => 'Se cerraron todas las sesiones del trabajador en la app.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(?WorkerAccount $account): array
    {
        if ($account === null) {
            return ['status' => self::STATUS_NONE, 'sessions' => []];
        }

        $status = match (true) {
            $account->temporaryNipExpired() => self::STATUS_EXPIRED,
            $account->hasTemporaryNip() => self::STATUS_TEMPORARY,
            default => self::STATUS_ACTIVE,
        };

        return [
            'status' => $status,
            'nip_expires_at' => $account->nip_expires_at?->toIso8601String(),
            'nip_issued_by' => $account->nipIssuedBy?->name,
            'activated_at' => $account->activated_at?->toIso8601String(),
            'last_login_at' => $account->last_login_at?->toIso8601String(),
            'sessions' => $account->devices()
                ->with('accessToken')
                ->latest()
                ->get()
                ->map(fn (WorkerDevice $device) => [
                    'id' => $device->id,
                    'device_name' => $device->device_name,
                    'platform' => $device->platform,
                    'os_version' => $device->os_version,
                    'app_version' => $device->app_version,
                    'push_enabled' => $device->expo_push_token !== null,
                    'started_at' => $device->created_at->toIso8601String(),
                    'last_used_at' => $device->accessToken?->last_used_at?->toIso8601String(),
                    'expires_at' => $device->accessToken?->expires_at?->toIso8601String(),
                ]),
        ];
    }
}
