<?php

namespace App\Notifications;

use App\Models\AttendancePunch;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push al teléfono del trabajador cuando se registra una checada suya, ya sea
 * en el checador o capturada a mano por RH.
 */
class PunchRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AttendancePunch $punch) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    /**
     * `channelId` es el canal Android que crea la app; `url` es la ruta de
     * expo-router que se abre al tocar la notificación (el calendario de
     * asistencia es la pantalla inicial).
     *
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => 'Checada registrada',
            'body' => $this->body(),
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
            'data' => ['url' => '/'],
        ];
    }

    private function body(): string
    {
        $label = $this->punch->type_label;
        $moment = sprintf(
            'el %s a las %s',
            $this->punch->punched_at->format('d/m/Y'),
            $this->punch->punched_at->format('H:i'),
        );

        if ($this->punch->source === AttendancePunch::SOURCE_MANUAL) {
            return "RH capturó una checada por ti: {$label} {$moment}.";
        }

        return "{$label} {$moment}.";
    }
}
