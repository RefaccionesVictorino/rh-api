<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWorkerDeviceRequest;
use App\Models\WorkerDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WorkerDeviceController extends Controller
{
    /** Registra el token de notificaciones push del teléfono de esta sesión. */
    public function update(UpdateWorkerDeviceRequest $request): JsonResponse
    {
        $device = WorkerDevice::where('personal_access_token_id', $request->user()->currentAccessToken()->id)
            ->firstOrFail();
        $pushToken = $request->validated('expo_push_token');

        DB::transaction(function () use ($device, $pushToken, $request): void {
            // Un token push pertenece a una sola instalación; si otra fila lo
            // conserva (p. ej. tras reinstalar la app), deja de ser suyo.
            if ($pushToken !== null) {
                WorkerDevice::where('expo_push_token', $pushToken)
                    ->whereKeyNot($device->id)
                    ->update(['expo_push_token' => null]);
            }

            $device->update([
                'expo_push_token' => $pushToken,
                'app_version' => $request->validated('app_version') ?? $device->app_version,
            ]);
        });

        return response()->json(['message' => 'Dispositivo actualizado.']);
    }
}
