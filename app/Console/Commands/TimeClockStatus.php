<?php

namespace App\Console\Commands;

use App\Models\TimeClockDevice;
use Illuminate\Console\Command;

/**
 * Diagnóstico rápido de las terminales. Pensado para la puesta en marcha:
 * responde de un vistazo si el equipo está hablando con el servidor.
 */
class TimeClockStatus extends Command
{
    protected $signature = 'time-clock:status';

    protected $description = 'Muestra el estado de las terminales de checado';

    public function handle(): int
    {
        $devices = TimeClockDevice::withCount('punches')->get();

        if ($devices->isEmpty()) {
            $this->warn('No hay terminales registradas.');
            $this->line('');
            $this->line('El alta ocurre sola en cuanto el equipo contacte al servidor.');
            $this->line('Revisa en el checador: Menú → Comm. → Cloud Server.');

            return self::SUCCESS;
        }

        $this->table(
            ['Serial', 'Nombre', 'IP', 'Estado', 'Último contacto', 'Checadas', 'Cmd. pend.'],
            $devices->map(fn (TimeClockDevice $device) => [
                $device->serial_number,
                $device->name,
                $device->ip_address ?? '—',
                $device->isOnline() ? 'EN LÍNEA' : 'sin señal',
                $device->last_seen_at?->diffForHumans() ?? 'nunca',
                $device->punches_count,
                $device->commands()->pending()->count(),
            ])->all()
        );

        return self::SUCCESS;
    }
}
