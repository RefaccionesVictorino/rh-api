<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\TimeClockDevice;
use App\Models\WorkerAccount;
use App\Notifications\PunchMissing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Avisa al trabajador que no ha checado entrada una vez pasado el umbral de
 * falta de su turno.
 *
 * Solo se evalúa cuando todos los checadores activos están en línea: al
 * reconectarse, el equipo reenvía primero las checadas que guardó sin red,
 * así que un equipo sin señal puede tener entradas que aún no conocemos y
 * avisar sería dar una falta en falso.
 */
class MissingPunchNotifier
{
    /**
     * Minutos después del umbral para que una checada hecha al filo alcance a
     * llegar del equipo y a procesarse.
     */
    private const GRACE_MINUTES = 5;

    /** @var array<int, Shift|null> */
    private array $shifts = [];

    public function __construct(private readonly AttendanceCalendarService $calendar) {}

    public function devicesSynced(): bool
    {
        $devices = TimeClockDevice::query()->where('is_active', true)->get();

        return $devices->isNotEmpty() && $devices->every(fn (TimeClockDevice $device) => $device->isOnline());
    }

    /**
     * Recorre a los trabajadores con turno vigente y teléfono registrado en la
     * app. Devuelve cuántos avisos se mandaron.
     *
     * Se evalúa también ayer: la entrada de un turno nocturno pertenece al
     * día en que empezó, y de madrugada ese día ya es "ayer".
     */
    public function notify(): int
    {
        $now = CarbonImmutable::now(config('time_clock.timezone'));
        $today = $now->startOfDay();
        $yesterday = $today->subDay();
        $notified = 0;

        Employee::query()
            ->whereHas('workerAccount.devices', fn (Builder $query) => $query->whereNotNull('expo_push_token'))
            ->whereHas('shiftAssignments', fn (Builder $query) => $query->overlapping($yesterday, $today))
            ->with('workerAccount')
            ->orderBy('id')
            ->chunkById(100, function (Collection $employees) use ($yesterday, $today, $now, &$notified): void {
                foreach ($employees as $employee) {
                    foreach ($this->calendar->evaluate($employee, $yesterday, $today) as $day) {
                        if ($this->shouldNotify($day, $now) && ! $this->alreadyNotified($employee->workerAccount, $day['date'])) {
                            $notified += $this->send($employee->workerAccount, $day['date']) ? 1 : 0;
                        }
                    }
                }
            });

        return $notified;
    }

    /**
     * Día laborable sin entrada cuyo umbral ya pasó y cuyo turno sigue en
     * curso: después de la salida ya no tiene caso avisar.
     *
     * @param  array<string, mixed>  $day
     */
    private function shouldNotify(array $day, CarbonImmutable $now): bool
    {
        $pending = in_array($day['status'], [
            AttendanceCalendarService::STATUS_PENDING,
            AttendanceCalendarService::STATUS_ABSENT,
        ], true);

        if (! $pending || $day['first_in'] !== null || $day['expected'] === null) {
            return false;
        }

        $timezone = $now->getTimezone();
        $startsAt = CarbonImmutable::parse($day['date'].' '.$day['expected']['start'], $timezone);
        $endsAt = CarbonImmutable::parse($day['date'].' '.$day['expected']['end'], $timezone);

        if ($endsAt->lte($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        $notifyAt = $startsAt->addMinutes($this->absenceThreshold($day['shift']['id'] ?? null) + self::GRACE_MINUTES);

        return $now->gte($notifyAt) && $now->lt($endsAt);
    }

    /**
     * Umbral de falta del turno; si no lo tiene, la tolerancia de retardo.
     */
    private function absenceThreshold(?int $shiftId): int
    {
        if ($shiftId === null) {
            return 0;
        }

        $shift = $this->shifts[$shiftId] ??= Shift::find($shiftId);

        return $shift?->absence_after_minutes ?? $shift?->tolerance_minutes ?? 0;
    }

    /**
     * Sin cola: el registro que evita repetir el aviso y el push salen en la
     * misma corrida. Encolado, un worker detenido acumularía un aviso por
     * corrida y los soltaría todos juntos al reanudarse.
     */
    private function send(WorkerAccount $account, string $date): bool
    {
        try {
            $account->notifyNow(new PunchMissing($date));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Aviso de falta sin enviar', ['worker_account_id' => $account->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Un aviso por trabajador y día del turno, aunque lo haya borrado de su
     * bandeja.
     */
    private function alreadyNotified(WorkerAccount $account, string $date): bool
    {
        return $account->notifications()
            ->withTrashed()
            ->where('type', PunchMissing::class)
            ->where('data->date', $date)
            ->exists();
    }
}
