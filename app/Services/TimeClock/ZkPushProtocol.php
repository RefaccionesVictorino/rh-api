<?php

namespace App\Services\TimeClock;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\TimeClockCommand;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Traduce el protocolo PUSH/ADMS de ZKTeco a datos del sistema.
 *
 * El terminal habla texto plano por HTTP: manda líneas separadas por
 * tabuladores y espera respuestas de una sola palabra. Toda esa rareza
 * queda contenida aquí para que controladores y modelos no la hereden.
 */
class ZkPushProtocol
{
    /**
     * Respuesta al saludo inicial (GET /iclock/cdata?SN=x&options=all).
     *
     * El equipo lee estas líneas para saber cada cuánto hablar y desde qué
     * punto reenviar. Texto plano, una directiva por línea, empezando por
     * GET OPTION FROM.
     */
    public function initialOptions(TimeClockDevice $device): string
    {
        $lines = [
            "GET OPTION FROM: {$device->serial_number}",

            // Nunca vacíos: un Stamp en blanco hace que algunos firmwares
            // omitan el historial pendiente en lugar de reenviarlo.
            'ATTLOGStamp='.($device->att_log_stamp ?: '0'),
            'OPERLOGStamp='.($device->op_log_stamp ?: '0'),
            'ATTPHOTOStamp=0',
            'ErrorDelay='.config('time_clock.error_delay'),
            'Delay='.config('time_clock.delay'),
            'TransTimes='.config('time_clock.trans_times'),
            'TransInterval='.config('time_clock.trans_interval'),

            // Qué tablas debe enviar: checadas, operaciones, usuarios y huellas.
            'TransFlag=TransData AttLog OpLog AttPhoto EnrollUser ChgUser EnrollFP ChgFP UserPic',

            'TimeZone='.config('time_clock.timezone_offset'),
            'Realtime='.config('time_clock.realtime'),
            'Encrypt=0',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Procesa un lote de checadas (POST table=ATTLOG).
     *
     * Cada línea trae campos separados por tabulador, en este orden:
     *   PIN  FechaHora  Estado  Verificación  WorkCode  Reservado...
     *
     * Devuelve cuántas checadas nuevas se guardaron.
     */
    public function processPunches(TimeClockDevice $device, string $body): int
    {
        $created = 0;
        $timezone = config('time_clock.timezone');
        $employeeIdsByPin = [];

        foreach ($this->lines($body) as $line) {
            $fields = explode("\t", $line);

            // Sin PIN y fecha la línea no sirve; se salta sin abortar el lote.
            if (count($fields) < 2 || trim($fields[0]) === '') {
                continue;
            }

            $pin = trim($fields[0]);
            $punchedAt = $this->parseDate(trim($fields[1]), $timezone);

            if ($punchedAt === null) {
                Log::warning('Checador: fecha ilegible', ['line' => $line]);

                continue;
            }

            // Un lote suele traer muchas checadas del mismo PIN: se resuelve el
            // empleado una sola vez por PIN.
            if (! array_key_exists($pin, $employeeIdsByPin)) {
                $employeeIdsByPin[$pin] = $this->employeeIdForPin($pin);
            }

            // firstOrCreate + índice único: si el equipo reenvía el lote porque
            // no le llegó el OK, no se duplica nada.
            $punch = AttendancePunch::firstOrCreate(
                [
                    'device_id' => $device->id,
                    'pin' => $pin,
                    'punched_at' => $punchedAt,
                    'punch_type' => (int) ($fields[2] ?? AttendancePunch::TYPE_IN),
                ],
                [
                    'employee_id' => $employeeIdsByPin[$pin],
                    'verify_mode' => (int) ($fields[3] ?? 0),
                    'work_code' => (int) ($fields[4] ?? 0),
                    'source' => 'device',
                    'raw_line' => $line,
                ]
            );

            if ($punch->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Procesa altas y cambios de usuario hechos en el propio equipo
     * (POST table=OPERLOG con líneas "USER PIN=...").
     *
     * Mantiene el catálogo local al día cuando alguien enrola directamente
     * en la pantalla del checador.
     */
    public function processOperations(TimeClockDevice $device, string $body): int
    {
        $processed = 0;

        foreach ($this->lines($body) as $line) {
            if (! str_starts_with($line, 'USER ')) {
                continue; // FP, FACE, OPLOG y demás no alteran el catálogo.
            }

            $data = $this->keyValuePairs(substr($line, 5));

            if (empty($data['PIN'])) {
                continue;
            }

            $user = TimeClockUser::firstOrNew(['pin' => $data['PIN']]);
            $user->fill([
                'name' => $this->valueOrNull($data['Name'] ?? null) ?? ('Usuario '.$data['PIN']),
                'card_number' => $this->valueOrNull($data['Card'] ?? null),
                'privilege' => (int) ($data['Pri'] ?? TimeClockUser::PRIVILEGE_USER),
                'synced_at' => now(),
            ]);

            // En este checador el PIN es el RFC del trabajador: si RH ya
            // capturó al empleado, se liga solo, sin captura adicional.
            $user->employee_id ??= Employee::where('rfc', $data['PIN'])->value('id');
            $user->save();

            $processed++;
        }

        return $processed;
    }

    /**
     * Entrega los comandos pendientes en el formato "C:<id>:<comando>".
     *
     * El id permite casar después la confirmación que manda el equipo.
     * Devuelve cadena vacía si no hay nada que hacer.
     */
    public function pendingCommands(TimeClockDevice $device, int $limit = 10): string
    {
        $pending = $device->commands()->pending()->limit($limit)->get();

        if ($pending->isEmpty()) {
            return '';
        }

        $output = $pending
            ->map(fn (TimeClockCommand $cmd) => "C:{$cmd->id}:{$cmd->command}")
            ->implode("\r\n");

        // Se marcan enviados en bloque para no re-entregarlos en el siguiente sondeo.
        TimeClockCommand::whereIn('id', $pending->pluck('id'))
            ->update(['sent_at' => now()]);

        return $output."\r\n";
    }

    /**
     * Registra la confirmación de un comando (POST /iclock/devicecmd).
     *
     * El cuerpo llega como "ID=3&Return=0&CMD=DATA", a veces varios separados
     * por saltos de línea. Return >= 0 significa éxito.
     */
    public function confirmCommands(TimeClockDevice $device, string $body): int
    {
        $confirmed = 0;

        foreach ($this->lines($body) as $line) {
            parse_str(str_replace("\t", '&', $line), $data);

            if (! isset($data['ID'])) {
                continue;
            }

            $confirmed += $device->commands()
                ->where('id', (int) $data['ID'])
                ->update([
                    'confirmed_at' => now(),
                    'result_code' => isset($data['Return']) ? (int) $data['Return'] : null,
                    'response' => $line,
                ]);
        }

        return $confirmed;
    }

    /**
     * Avanza la marca de agua del equipo.
     *
     * El terminal manda el Stamp del lote y espera que el servidor lo recuerde;
     * en el siguiente saludo reenviará solo lo posterior a ese punto.
     */
    public function updateStamp(TimeClockDevice $device, ?string $stamp, string $column = 'att_log_stamp'): void
    {
        if ($stamp !== null && $stamp !== '') {
            $device->forceFill([$column => $stamp])->save();
        }
    }

    public function recordContact(TimeClockDevice $device, ?string $ip = null): void
    {
        $device->forceFill(array_filter([
            'last_seen_at' => now(),
            'ip_address' => $ip,
        ]))->save();
    }

    /**
     * Busca el equipo por serial; lo da de alta si la configuración lo permite.
     */
    public function resolveDevice(string $serialNumber): ?TimeClockDevice
    {
        $device = TimeClockDevice::where('serial_number', $serialNumber)->first();

        if ($device !== null) {
            return $device;
        }

        if (! config('time_clock.auto_register')) {
            Log::warning('Checador: serial desconocido rechazado', ['serial_number' => $serialNumber]);

            return null;
        }

        return TimeClockDevice::create([
            'serial_number' => $serialNumber,
            'name' => 'Terminal '.$serialNumber,
        ]);
    }

    /**
     * Empleado al que pertenece un PIN: el ligado al usuario del checador o,
     * en su defecto, el que tenga ese RFC (en este equipo el PIN es el RFC).
     */
    private function employeeIdForPin(string $pin): ?int
    {
        $employeeId = TimeClockUser::where('pin', $pin)->value('employee_id')
            ?? Employee::where('rfc', $pin)->value('id');

        return $employeeId === null ? null : (int) $employeeId;
    }

    /**
     * El equipo manda la cadena literal "null" en los campos vacíos.
     */
    private function valueOrNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return in_array($value, [null, '', 'null'], true) ? null : $value;
    }

    /**
     * Divide un cuerpo en líneas limpias, tolerando \r\n y \n.
     *
     * @return list<string>
     */
    private function lines(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $body)),
            fn (string $line) => $line !== ''
        ));
    }

    /**
     * Convierte "PIN=1\tName=Ana" en arreglo asociativo.
     *
     * @return array<string, string>
     */
    private function keyValuePairs(string $text): array
    {
        $data = [];

        // Los campos vienen separados por tabulador; algunos firmwares usan espacios.
        foreach (preg_split('/\t+/', trim($text)) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $data[trim($key)] = trim($value);
            }
        }

        return $data;
    }

    /**
     * Interpreta la fecha que manda el equipo.
     *
     * Se guarda tal cual la marca el reloj del terminal, sin convertir a UTC:
     * en nómina la hora que importa es la del reloj de pared, y convertir
     * desplazaría las checadas de la tarde al día siguiente, rompiendo
     * cualquier corte por día.
     */
    private function parseDate(string $value, string $timezone): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', trim($value), $timezone);
        } catch (\Throwable) {
            // Algunos firmwares usan separadores distintos; se intenta libre.
            try {
                return Carbon::parse($value, $timezone);
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
