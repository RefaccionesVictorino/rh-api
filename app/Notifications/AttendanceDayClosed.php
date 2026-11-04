<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Resumen de incidencias de un día ya cerrado, para quienes ven asistencia.
 * Un aviso por día y no uno por empleado, para no inundar la bandeja.
 */
class AttendanceDayClosed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $date,
        public readonly int $absences,
        public readonly int $lateArrivals,
        public readonly int $missingOuts,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Incidencias del '.CarbonImmutable::parse($this->date)->format('d/m/Y'),
            'message' => $this->message(),
            'url' => '/incidencias?from='.$this->date.'&to='.$this->date,
            'date' => $this->date,
            'absences' => $this->absences,
            'late_arrivals' => $this->lateArrivals,
            'missing_outs' => $this->missingOuts,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->subject($data['title'])
            ->greeting('Hola, '.$notifiable->name)
            ->line($data['message'])
            ->action('Revisar incidencias', config('app.frontend_url').$data['url']);
    }

    private function message(): string
    {
        $parts = array_filter([
            $this->count($this->absences, 'falta', 'faltas'),
            $this->count($this->lateArrivals, 'retardo', 'retardos'),
            $this->count($this->missingOuts, 'día sin checar salida', 'días sin checar salida'),
        ]);

        return 'Al cerrar el día quedaron '.implode(', ', $parts).'.';
    }

    private function count(int $value, string $singular, string $plural): ?string
    {
        if ($value === 0) {
            return null;
        }

        return $value.' '.($value === 1 ? $singular : $plural);
    }
}
