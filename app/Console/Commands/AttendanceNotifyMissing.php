<?php

namespace App\Console\Commands;

use App\Services\MissingPunchNotifier;
use Illuminate\Console\Command;

/**
 * Aviso al trabajador que no ha checado. Corre periódicamente y no al
 * cumplirse el umbral de cada turno: el aviso llega, a lo más, un intervalo
 * después de que el turno lo marque como falta.
 */
class AttendanceNotifyMissing extends Command
{
    protected $signature = 'attendance:notify-missing';

    protected $description = 'Avisa por push a los trabajadores sin entrada registrada pasado el umbral de falta de su turno';

    public function handle(MissingPunchNotifier $notifier): int
    {
        if (! $notifier->devicesSynced()) {
            $this->warn('Hay checadores sin señal; no se avisa a nadie hasta que todos sincronicen.');

            return self::SUCCESS;
        }

        $notified = $notifier->notify();

        $this->info(sprintf('Avisado(s) %d trabajador(es) sin entrada.', $notified));

        return self::SUCCESS;
    }
}
