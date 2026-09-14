<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Shift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'tolerance_minutes',
        'absence_after_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tolerance_minutes' => 'integer',
            'absence_after_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Los 7 días de la semana del turno, de domingo a sábado. */
    public function days(): HasMany
    {
        return $this->hasMany(ShiftDay::class)->orderBy('weekday');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    /** Asignaciones vigentes hoy. */
    public function currentAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShift::class)->activeOn(today());
    }

    /**
     * Horario de un día concreto de la semana (0 = domingo … 6 = sábado).
     * Usa la relación ya cargada cuando existe, para no pegarle a la base por
     * cada día al calcular asistencia.
     */
    public function dayFor(int $weekday): ?ShiftDay
    {
        return $this->days->firstWhere('weekday', $weekday);
    }

    /**
     * Reemplaza el horario semanal completo.
     *
     * Recibe solo los días que el cliente mandó; los que faltan se guardan
     * como descanso, de modo que el turno siempre queda con sus 7 renglones.
     *
     * @param  list<array<string, mixed>>  $days
     */
    public function syncDays(array $days): void
    {
        $byWeekday = collect($days)->keyBy(fn (array $day) => (int) $day['weekday']);

        DB::transaction(function () use ($byWeekday): void {
            $this->days()->delete();

            foreach (ShiftDay::WEEKDAYS as $weekday => $name) {
                $day = $byWeekday->get($weekday);

                $rest = $day === null || (bool) ($day['is_rest_day'] ?? false);

                $this->days()->create([
                    'weekday' => $weekday,
                    'is_rest_day' => $rest,
                    'start_time' => $rest ? null : $day['start_time'],
                    'end_time' => $rest ? null : $day['end_time'],
                    'break_start' => $rest ? null : ($day['break_start'] ?? null),
                    'break_end' => $rest ? null : ($day['break_end'] ?? null),
                ]);
            }
        });

        $this->unsetRelation('days');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('description', 'like', $like);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
