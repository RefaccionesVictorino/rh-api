<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

/**
 * Push al trabajador que no ha checado su entrada pasado el umbral de falta
 * de su turno. `date` es el día del turno, no el del envío: en un turno
 * nocturno el aviso puede salir de madrugada y sirve para no repetirlo.
 *
 * No va por la cola a propósito; ver MissingPunchNotifier::send().
 */
class PunchMissing extends Notification
{
    public function __construct(public readonly string $date) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', ExpoPushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => 'Sin registro de asistencia',
            'body' => $this->body(),
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
            'data' => ['url' => '/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Sin registro de asistencia',
            'message' => $this->body(),
            'url' => '/',
            'date' => $this->date,
        ];
    }

    private function body(): string
    {
        $date = CarbonImmutable::parse($this->date)->format('d/m/Y');

        return "No se registró tu asistencia del {$date}. Si crees que es un error, contacta a RH.";
    }
}
