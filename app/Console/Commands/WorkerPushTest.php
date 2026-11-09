<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Notifications\TestPush;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Http\Client\HttpClientException;

/**
 * Envía una push de prueba al teléfono del trabajador, directo al servicio de
 * Expo y sin pasar por la cola, para verificar credenciales FCM, token y canal
 * sin depender de un queue worker.
 */
class WorkerPushTest extends Command implements PromptsForMissingInput
{
    protected $signature = 'worker:push-test
                            {employee : ID del empleado}
                            {message : Texto de la notificación}';

    protected $description = 'Envía una notificación push de prueba a todos los teléfonos del trabajador, sin cola';

    public function handle(): int
    {
        $employee = Employee::find($this->argument('employee'));

        if ($employee === null) {
            $this->error("No existe un empleado con ID {$this->argument('employee')}.");

            return self::FAILURE;
        }

        $account = $employee->workerAccount;

        if ($account === null) {
            $this->error("{$employee->full_name} no tiene cuenta en la app; genera su NIP desde la ficha del empleado.");

            return self::FAILURE;
        }

        $tokens = $account->routeNotificationForExpo();

        if ($tokens === []) {
            $this->error("{$employee->full_name} no tiene teléfonos con token push. La app lo registra al abrir sesión en un development build de Android.");

            return self::FAILURE;
        }

        try {
            $account->notifyNow(new TestPush($this->argument('message')));
        } catch (HttpClientException $e) {
            $this->error('El servicio de Expo rechazó el envío: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Enviada a %d teléfono(s) de %s.', count($tokens), $employee->full_name));
        $this->line('Si no llega, revisa las credenciales FCM V1 en EAS (eas credentials) y que la app sea un development build.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'employee' => 'ID del empleado',
            'message' => 'Texto de la notificación',
        ];
    }
}
