<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Simula un terminal ZKTeco contra el servidor local.
 *
 * Reproduce el ciclo real del protocolo PUSH: saludo, envío de checadas,
 * sondeo de comandos y confirmación. Permite desarrollar y depurar toda la
 * integración sin tener el equipo físico enfrente.
 */
class TimeClockSimulate extends Command
{
    protected $signature = 'time-clock:simulate
        {--url=http://127.0.0.1:8000 : URL base del servidor}
        {--sn=SIM0001 : Serial del terminal simulado}
        {--pin=1001 : PIN del empleado que checa}
        {--poll : Queda sondeando comandos en bucle, como el equipo real}';

    protected $description = 'Simula un checador ZKTeco para probar la integración en local';

    private string $url;

    private string $serialNumber;

    public function handle(): int
    {
        $this->url = rtrim($this->option('url'), '/');
        $this->serialNumber = $this->option('sn');

        $this->info("Simulando terminal {$this->serialNumber} contra {$this->url}");
        $this->line('');

        if (! $this->handshake()) {
            return self::FAILURE;
        }

        if ($this->option('poll')) {
            return $this->pollForever();
        }

        $this->sendPunch();
        $this->pollCommands();

        $this->line('');
        $this->info('Listo. Revisa el resultado con: php artisan time-clock:status');

        return self::SUCCESS;
    }

    /** Saludo inicial: el equipo pide su configuración de trabajo. */
    private function handshake(): bool
    {
        $this->comment('1. Saludo inicial (GET /iclock/cdata?options=all)');

        try {
            $response = Http::timeout(10)->get("{$this->url}/iclock/cdata", [
                'SN' => $this->serialNumber,
                'options' => 'all',
                'pushver' => '2.4.1',
            ]);
        } catch (\Throwable $e) {
            $this->error('   No hay respuesta del servidor: '.$e->getMessage());
            $this->line('   ¿Está corriendo `php artisan serve`?');

            return false;
        }

        if (! $response->successful()) {
            $this->error('   HTTP '.$response->status());

            return false;
        }

        foreach (preg_split('/\r\n|\n/', trim($response->body())) as $line) {
            $this->line('   '.$line);
        }

        // Sin esta línea el firmware descarta la respuesta y no se registra.
        if (! str_contains($response->body(), 'GET OPTION FROM')) {
            $this->error('   Falta la cabecera GET OPTION FROM: el equipo real rechazaría esto.');

            return false;
        }

        $this->line('');

        return true;
    }

    /** Manda una checada con la hora actual, como al pasar la cara. */
    private function sendPunch(): void
    {
        $this->comment('2. Enviando checada (POST table=ATTLOG)');

        $pin = $this->option('pin');
        $punchedAt = now(config('time_clock.timezone'))->format('Y-m-d H:i:s');

        // PIN, fecha, estado (0=entrada), verificación (15=rostro), workcode.
        $line = implode("\t", [$pin, $punchedAt, '0', '15', '0', '0']);

        $response = Http::withBody($line."\n", 'text/plain')
            ->post("{$this->url}/iclock/cdata?".http_build_query([
                'SN' => $this->serialNumber,
                'table' => 'ATTLOG',
                'Stamp' => now()->timestamp,
            ]));

        $this->line("   Enviado: PIN={$pin} a las {$punchedAt}");
        $this->line('   Respuesta: '.trim($response->body()));
        $this->line('');
    }

    /** Recoge comandos pendientes y confirma su ejecución. */
    private function pollCommands(): int
    {
        $this->comment('3. Sondeo de comandos (GET /iclock/getrequest)');

        $response = Http::get("{$this->url}/iclock/getrequest", ['SN' => $this->serialNumber]);
        $body = trim($response->body());

        if ($body === '' || $body === 'OK') {
            $this->line('   Sin comandos pendientes.');
            $this->line('');

            return 0;
        }

        $confirmations = [];

        foreach (preg_split('/\r\n|\n/', $body) as $line) {
            if (! preg_match('/^C:(\d+):(.*)$/s', trim($line), $m)) {
                continue;
            }

            $this->line("   Recibido #{$m[1]}: ".str_replace("\t", ' ', mb_substr($m[2], 0, 70)));

            // El equipo real responde Return=0 al ejecutar sin error.
            $confirmations[] = "ID={$m[1]}&Return=0&CMD=".strtok($m[2], ' ');
        }

        if ($confirmations === []) {
            return 0;
        }

        Http::withBody(implode("\n", $confirmations)."\n", 'text/plain')
            ->post("{$this->url}/iclock/devicecmd?SN={$this->serialNumber}");

        $this->line('   Confirmados '.count($confirmations).' comando(s).');
        $this->line('');

        return count($confirmations);
    }

    /**
     * Modo continuo: sondea cada pocos segundos igual que el equipo real,
     * para ver llegar los comandos que se encolan desde el frontend.
     */
    private function pollForever(): int
    {
        $interval = max(3, (int) config('time_clock.delay', 30));

        $this->info("Sondeando cada {$interval}s. Ctrl+C para salir.");
        $this->line('Encola comandos desde la API y aparecerán aquí.');
        $this->line('');

        while (true) {
            $handled = $this->pollCommands();

            if ($handled === 0) {
                $this->line('   ['.now()->format('H:i:s').'] sin novedades');
            }

            sleep($interval);
        }
    }
}
