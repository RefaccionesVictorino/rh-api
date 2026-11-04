<?php

namespace App\Http\Controllers\TimeClock;

use App\Http\Controllers\Controller;
use App\Models\TimeClockDevice;
use App\Services\TimeClock\ZkPushProtocol;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints que consume directamente el terminal ZKTeco.
 *
 * Las rutas /iclock/* están fijas en el firmware; por eso este controlador
 * conserva ese nombre. Reglas del protocolo que condicionan todo aquí:
 *  - Las respuestas son texto plano. Un JSON hace que el equipo descarte el lote.
 *  - "OK" es el acuse. Si no lo recibe, reenvía las mismas checadas.
 *  - Siempre se responde 200: un 4xx/5xx deja al equipo reintentando en bucle.
 */
class IclockController extends Controller
{
    public function __construct(private readonly ZkPushProtocol $protocol) {}

    /**
     * GET  /iclock/cdata?SN=x&options=all  → saludo inicial, devuelve configuración
     * POST /iclock/cdata?SN=x&table=ATTLOG → lote de checadas u operaciones
     */
    public function cdata(Request $request): Response
    {
        $this->log('cdata', $request);

        $device = $this->device($request);

        if ($device === null) {
            // "OK" y no un error: así el equipo no entra en bucle de reintentos.
            return $this->plain('OK');
        }

        $this->protocol->recordContact($device, $request->ip());

        // Saludo inicial: el equipo pide su configuración de trabajo.
        if ($request->isMethod('get')) {
            return $this->plain($this->protocol->initialOptions($device));
        }

        $table = strtoupper((string) $request->query('table'));
        $body = $request->getContent();

        $count = match ($table) {
            'ATTLOG' => $this->protocol->processPunches($device, $body),
            'OPERLOG' => $this->protocol->processOperations($device, $body),
            default => 0, // ATTPHOTO, BIODATA y demás: se acusan sin procesar.
        };

        // Se avanza la marca de agua solo tras guardar, para no perder datos
        // si algo falla a media faena. El equipo real manda OpStamp en los
        // lotes de operaciones y Stamp en los de checadas.
        $this->protocol->updateStamp(
            $device,
            $request->query('OpStamp', $request->query('Stamp')),
            $table === 'OPERLOG' ? 'op_log_stamp' : 'att_log_stamp'
        );

        // El equipo espera "OK: <n>" en los lotes de datos.
        return $this->plain("OK: {$count}");
    }

    /**
     * GET /iclock/getrequest?SN=x
     *
     * Sondeo periódico. Se contesta con los comandos pendientes o con "OK"
     * cuando no hay nada que hacer.
     */
    public function getrequest(Request $request): Response
    {
        $this->log('getrequest', $request);

        $device = $this->device($request);

        if ($device === null) {
            return $this->plain('OK');
        }

        $this->protocol->recordContact($device, $request->ip());

        $commands = $this->protocol->pendingCommands($device);

        return $this->plain($commands !== '' ? $commands : 'OK');
    }

    /**
     * POST /iclock/devicecmd?SN=x
     *
     * El equipo reporta el resultado de los comandos que ejecutó.
     */
    public function devicecmd(Request $request): Response
    {
        $this->log('devicecmd', $request);

        $device = $this->device($request);

        if ($device !== null) {
            $this->protocol->confirmCommands($device, $request->getContent());
        }

        return $this->plain('OK');
    }

    /**
     * POST /iclock/fdata?SN=x
     *
     * Plantillas biométricas. No se almacenan: son formato propietario y solo
     * sirven dentro del equipo. Se acusan para que no reintente.
     */
    public function fdata(Request $request): Response
    {
        $this->log('fdata', $request);

        return $this->plain('OK');
    }

    /**
     * Localiza el equipo validando la llave de acceso si está configurada.
     */
    private function device(Request $request): ?TimeClockDevice
    {
        $key = config('time_clock.access_key');

        if ($key && ! hash_equals((string) $key, (string) $request->query('key'))) {
            Log::warning('Checador: llave inválida', ['ip' => $request->ip()]);

            return null;
        }

        $serialNumber = trim((string) $request->query('SN'));

        if ($serialNumber === '') {
            return null;
        }

        return $this->protocol->resolveDevice($serialNumber);
    }

    /** El equipo solo entiende text/plain. */
    private function plain(string $content): Response
    {
        return response($content, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function log(string $endpoint, Request $request): void
    {
        if (! config('time_clock.log')) {
            return;
        }

        Log::channel(config('time_clock.log_channel'))->info("Checador [{$endpoint}]", [
            'method' => $request->method(),
            'query' => $request->query(),
            'body' => mb_substr($request->getContent(), 0, 2000),
            'ip' => $request->ip(),
        ]);
    }
}
