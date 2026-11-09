<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Notifications\Notification;

/**
 * Push con texto libre para probar el canal de Expo desde la consola
 * (worker:push-test). No se encola: el envío se ve y falla en el momento.
 */
class TestPush extends Notification
{
    public function __construct(public readonly string $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => 'Notificación de prueba',
            'body' => $this->message,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
            'data' => ['url' => '/'],
        ];
    }
}
