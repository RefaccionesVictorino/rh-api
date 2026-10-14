<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\User;
use App\Notifications\AttendanceDayClosed;
use App\Services\AttendanceCalendarService;
use App\Services\AttendanceRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Cierre diario de asistencia.
 *
 * Una falta es la ausencia de checada, así que ningún evento la dispara: este
 * comando evalúa a toda la plantilla para el día cerrado y avisa cuántas
 * incidencias quedaron. Recalcula además unos días hacia atrás, por si una
 * checada llegó tarde del equipo y su disparador no corrió.
 */
class AttendanceClose extends Command
{
    protected $signature = 'attendance:close
                            {--date= : Día a cerrar (Y-m-d); por omisión, ayer}
                            {--days=3 : Cuántos días hacia atrás recalcular, contando el cerrado}
                            {--no-notify : No enviar el resumen de incidencias}';

    protected $description = 'Guarda el resumen de asistencia del día cerrado y avisa las incidencias';

    public function __construct(private readonly AttendanceRecorder $recorder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $timezone = config('time_clock.timezone');
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'), $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay()->subDay();

        if ($date->gte(CarbonImmutable::now($timezone)->startOfDay())) {
            $this->error('Solo se pueden cerrar días anteriores a hoy.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $from = $date->subDays($days - 1);

        $written = $this->recorder->recordAll($from, $date);

        $this->info(sprintf(
            'Cerrado %s: %d renglón(es) evaluados del %s al %s.',
            $date->toDateString(),
            $written,
            $from->toDateString(),
            $date->toDateString(),
        ));

        if (! $this->option('no-notify')) {
            $this->notify($date);
        }

        return self::SUCCESS;
    }

    private function notify(CarbonImmutable $date): void
    {
        $rows = AttendanceDay::query()
            ->whereDate('date', $date->toDateString())
            ->justified(false);

        $absences = (clone $rows)->where('status', AttendanceCalendarService::STATUS_ABSENT)->count();
        $late = (clone $rows)->where('status', AttendanceCalendarService::STATUS_LATE)->count();
        $missingOuts = (clone $rows)->where('missing_out', true)->count();

        if ($absences + $late + $missingOuts === 0) {
            $this->line('Sin incidencias que avisar.');

            return;
        }

        // Sin el scope permission() de Spatie: lanza excepción si el permiso
        // no existe, y el cierre no debe fallar por falta de destinatarios.
        $hasPermission = fn (Builder $q) => $q->where('name', 'asistencia.ver');

        $recipients = User::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('permissions', $hasPermission)
                ->orWhereHas('roles.permissions', $hasPermission))
            ->get();

        Notification::send($recipients, new AttendanceDayClosed($date->toDateString(), $absences, $late, $missingOuts));

        $this->line(sprintf(
            'Avisadas %d persona(s): %d falta(s), %d retardo(s), %d sin salida.',
            $recipients->count(),
            $absences,
            $late,
            $missingOuts,
        ));
    }
}
