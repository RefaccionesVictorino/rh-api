<?php

namespace App\Services\TimeClock;

use App\Models\TimeClockCommand;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;

/**
 * Constructor de comandos del protocolo PUSH.
 *
 * Los comandos no se ejecutan al instante: se dejan en cola y el equipo los
 * recoge en su siguiente sondeo (por defecto cada 30 s). Cada método devuelve
 * el registro encolado para poder seguir su confirmación.
 */
class ZkCommandBuilder
{
    /**
     * Da de alta o actualiza un usuario en el terminal.
     *
     * Sobre el rostro: esta orden crea la ficha del usuario (PIN, nombre,
     * privilegio, tarjeta, contraseña), pero NO la plantilla facial. El rostro
     * se enrola frente al equipo. El flujo normal es: alta desde la API, y el
     * empleado pasa una vez a registrar su cara.
     */
    public function upsertUser(TimeClockDevice $device, TimeClockUser $user): TimeClockCommand
    {
        $fields = [
            'PIN='.$user->pin,
            'Name='.$this->sanitize($user->name),

            // Nunca vacío: el equipo descarta el alta si Pri no trae valor.
            'Pri='.($user->privilege ?? TimeClockUser::PRIVILEGE_USER),
            'Passwd='.($user->password ?? ''),
            'Card='.($user->card_number ?? ''),
            'Grp=1',
            'TZ=0000000000000000',
        ];

        return $device->queueCommand('DATA UPDATE USERINFO '.implode("\t", $fields), 'user_upsert');
    }

    /**
     * Carga en el terminal todos los usuarios activos del catálogo.
     *
     * Se envían los datos guardados en `time_clock_users`, no los del
     * expediente, para que el equipo quede igual que los demás. Repetirlo no
     * daña nada: USERINFO actualiza por PIN y conserva el rostro enrolado.
     *
     * @return int Usuarios encolados.
     */
    public function syncUsers(TimeClockDevice $device): int
    {
        $users = TimeClockUser::where('is_active', true)->orderBy('id')->get();

        $users->each(fn (TimeClockUser $user) => $this->upsertUser($device, $user));

        return $users->count();
    }

    /** Borra al usuario del terminal, incluidas sus plantillas biométricas. */
    public function deleteUser(TimeClockDevice $device, string $pin): TimeClockCommand
    {
        return $device->queueCommand("DATA DELETE USERINFO PIN={$pin}", 'user_delete');
    }

    /** Pide al equipo que reenvíe todo su catálogo de usuarios. */
    public function queryUsers(TimeClockDevice $device): TimeClockCommand
    {
        return $device->queueCommand('DATA QUERY USERINFO', 'query_users');
    }

    /**
     * Pide reenviar checadas de un rango. Sirve para recuperar días perdidos
     * si el servidor estuvo caído.
     */
    public function queryPunches(TimeClockDevice $device, string $from, string $to): TimeClockCommand
    {
        return $device->queueCommand(
            "DATA QUERY ATTLOG StartTime={$from}\tEndTime={$to}",
            'query_punches'
        );
    }

    /** Ajusta el reloj del equipo a la hora del servidor. */
    public function syncTime(TimeClockDevice $device): TimeClockCommand
    {
        $now = now(config('time_clock.timezone'))->format('Y-m-d H:i:s');

        return $device->queueCommand("SET OPTION DateTime={$this->toZkInteger($now)}", 'sync_time');
    }

    public function reboot(TimeClockDevice $device): TimeClockCommand
    {
        return $device->queueCommand('REBOOT', 'reboot');
    }

    /**
     * Borra la bitácora de checadas del equipo.
     *
     * Es irreversible en el terminal. Solo cuando ya está todo respaldado en
     * la base de datos.
     */
    public function clearLog(TimeClockDevice $device): TimeClockCommand
    {
        return $device->queueCommand('CLEAR LOG', 'clear_log');
    }

    /** Abre la chapa o relé por los segundos indicados. */
    public function unlockDoor(TimeClockDevice $device, int $seconds = 5): TimeClockCommand
    {
        return $device->queueCommand("AC_UNLOCK={$seconds}", 'unlock_door');
    }

    /** Muestra un mensaje corto en la pantalla del equipo. */
    public function message(TimeClockDevice $device, string $text, int $seconds = 10): TimeClockCommand
    {
        return $device->queueCommand(
            'DATA UPDATE SMS '."MSG={$this->sanitize($text)}\tTAG=1\tMIN={$seconds}",
            'message'
        );
    }

    /**
     * El protocolo codifica la fecha como un entero propio de ZK.
     * Fórmula oficial del SDK.
     */
    private function toZkInteger(string $date): int
    {
        $t = strtotime($date);

        return ((int) date('Y', $t) - 2000) * 12 * 31 * 24 * 60 * 60
            + ((int) date('n', $t) - 1) * 31 * 24 * 60 * 60
            + ((int) date('j', $t) - 1) * 24 * 60 * 60
            + (int) date('G', $t) * 60 * 60
            + (int) date('i', $t) * 60
            + (int) date('s', $t);
    }

    /** Los tabuladores y saltos rompen el formato del comando. */
    private function sanitize(string $value): string
    {
        return trim(str_replace(["\t", "\r", "\n"], ' ', $value));
    }
}
