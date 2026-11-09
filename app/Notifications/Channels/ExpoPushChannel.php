<?php

namespace App\Notifications\Channels;

use App\Models\WorkerDevice;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Canal del servicio de push de Expo. El notifiable lista sus tokens en
 * routeNotificationForExpo() y la notificación arma su mensaje en toExpo().
 */
class ExpoPushChannel
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    private const BATCH_SIZE = 100;

    public function send(object $notifiable, Notification $notification): void
    {
        $tokens = $notifiable->routeNotificationFor('expo', $notification);

        if (empty($tokens)) {
            return;
        }

        /** @var array<string, mixed> $message */
        $message = $notification->toExpo($notifiable); // @phpstan-ignore method.notFound

        foreach (array_chunk(array_values($tokens), self::BATCH_SIZE) as $batch) {
            $this->deliver($batch, $message);
        }
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $message
     */
    private function deliver(array $tokens, array $message): void
    {
        $payload = array_map(fn (string $token) => ['to' => $token] + $message, $tokens);

        // throw(): un fallo del servicio deja que la cola reintente el envío.
        $tickets = Http::acceptJson()
            ->post(self::ENDPOINT, $payload)
            ->throw()
            ->json('data', []);

        // Los tickets llegan en el orden del envío. Un DeviceNotRegistered
        // (app desinstalada o permiso retirado) retira el token para siempre.
        foreach ($tickets as $index => $ticket) {
            if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($tokens[$index])) {
                WorkerDevice::where('expo_push_token', $tokens[$index])->update(['expo_push_token' => null]);
            }
        }
    }
}
