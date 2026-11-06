<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeWorkerNipRequest;
use App\Http\Requests\WorkerLoginRequest;
use App\Models\WorkerAccount;
use App\Services\WorkerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesión de la app móvil del trabajador. El primer acceso llega con el NIP
 * temporal de RH: se responde 409 y la app repite el login con `new_nip`.
 */
class WorkerAuthController extends Controller
{
    public function __construct(private readonly WorkerAccessService $access) {}

    public function login(WorkerLoginRequest $request): JsonResponse
    {
        $account = $request->account();

        if ($account->hasTemporaryNip()) {
            if (! $request->filled('new_nip')) {
                return response()->json([
                    'message' => 'Crea tu NIP personal para continuar.',
                    'code' => 'nip_change_required',
                ], 409);
            }

            $this->access->setNip($account, $request->validated('new_nip'));
        }

        $token = $this->access->startSession($account, $request->device());

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->toIso8601String(),
            'worker' => $this->workerPayload($account),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workerPayload($request->user())]);
    }

    /** Cierra solo la sesión de este teléfono. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function updateNip(ChangeWorkerNipRequest $request): JsonResponse
    {
        $this->access->setNip($request->user(), $request->validated('new_nip'));

        return response()->json(['message' => 'Tu NIP se actualizó.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function workerPayload(WorkerAccount $account): array
    {
        $employee = $account->employee;

        return [
            'name' => $employee->name,
            'full_name' => $employee->full_name,
            'photo_url' => $employee->signed_photo_url,
        ];
    }
}
