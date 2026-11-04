<?php

namespace App\Notifications;

use App\Models\VacationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a quienes autorizan vacaciones de que hay una solicitud pendiente.
 */
class VacationRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly VacationRequest $vacationRequest) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    /**
     * Mismo contenido para la bandeja y para Reverb. `url` es relativa al
     * panel: el frontend la usa tal cual y el correo le antepone el dominio.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Nueva solicitud de vacaciones',
            'message' => sprintf(
                '%s pidió %s día(s): del %s al %s.',
                $this->vacationRequest->employee->full_name,
                $this->formattedDays(),
                $this->vacationRequest->starts_on->format('d/m/Y'),
                $this->vacationRequest->ends_on->format('d/m/Y'),
            ),
            'url' => '/vacaciones/solicitudes?estatus='.VacationRequest::PENDING,
            'vacation_request_id' => $this->vacationRequest->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->subject($data['title'])
            ->greeting('Hola, '.$notifiable->name)
            ->line($data['message'])
            ->action('Revisar solicitudes', config('app.frontend_url').$data['url']);
    }

    private function formattedDays(): string
    {
        return rtrim(rtrim(number_format($this->vacationRequest->requested_days, 1), '0'), '.');
    }
}
