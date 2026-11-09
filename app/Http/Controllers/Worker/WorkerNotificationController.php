<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bandeja del trabajador en la app: lo mismo que llega a su teléfono por
 * push. Igual que en el panel, cada quien solo ve y marca las suyas, por eso
 * todo pasa por la relación y no por route model binding.
 */
class WorkerNotificationController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $request->user();

        return NotificationResource::collection($account->notifications()->paginate(self::PER_PAGE))
            ->additional(['meta' => ['unread_count' => $account->unreadNotifications()->count()]]);
    }

    public function markAsRead(Request $request, string $notification): NotificationResource
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return NotificationResource::make($record);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['meta' => ['unread_count' => 0]]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->findOrFail($notification)->delete();

        return response()->json(['meta' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json(['meta' => ['unread_count' => 0]]);
    }
}
