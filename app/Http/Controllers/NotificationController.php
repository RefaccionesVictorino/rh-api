<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bandeja del usuario en sesión. Cada quien solo ve y marca las suyas, por
 * eso todo pasa por la relación y no por route model binding.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        return NotificationResource::collection($user->notifications()->paginate(self::PER_PAGE))
            ->additional(['meta' => ['unread_count' => $user->unreadNotifications()->count()]]);
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
}
