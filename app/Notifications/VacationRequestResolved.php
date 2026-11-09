<?php

namespace App\Notifications;

use App\Models\VacationRequest;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push al teléfono del trabajador cuando RH resuelve o cancela su solicitud.
 */
class VacationRequestResolved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly VacationRequest $vacationRequest) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    /**
     * `channelId` es el canal Android que crea la app; `url` es la ruta de
     * expo-router que se abre al tocar la notificación.
     *
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => match ($this->vacationRequest->status) {
                VacationRequest::APPROVED => 'Vacaciones aprobadas',
                VacationRequest::CANCELLED => 'Vacaciones canceladas',
                default => 'Solicitud de vacaciones rechazada',
            },
            'body' => $this->body(),
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
            'data' => ['url' => '/vacaciones'],
        ];
    }

    private function body(): string
    {
        $period = sprintf(
            'del %s al %s',
            $this->vacationRequest->starts_on->format('d/m/Y'),
            $this->vacationRequest->ends_on->format('d/m/Y'),
        );

        if ($this->vacationRequest->status === VacationRequest::CANCELLED) {
            return "Tu solicitud de vacaciones {$period} fue cancelada.";
        }

        if ($this->vacationRequest->status === VacationRequest::REJECTED) {
            $reason = $this->vacationRequest->rejection_reason;

            return "Tu solicitud {$period} fue rechazada".($reason !== null ? ": {$reason}" : '.');
        }

        // requested_starts_on solo existe cuando RH reprogramó al aprobar.
        if ($this->vacationRequest->requested_starts_on !== null) {
            return "RH cambió las fechas: tus vacaciones serán {$period}.";
        }

        return "Tus vacaciones {$period} fueron aprobadas.";
    }
}
