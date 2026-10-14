<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexAttendanceIncidentRequest;
use App\Http\Resources\AttendanceDayResource;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\SubDepartment;
use App\Services\AttendanceCalendarService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Incidencias de toda la empresa, leídas del resumen diario guardado.
 *
 * Solo hay renglones de días cerrados: el día en curso no aparece aquí hasta
 * que corre attendance:close.
 */
class AttendanceIncidentController extends Controller
{
    private const RELATIONS = ['employee.subDepartment.department', 'shift', 'justifiedBy'];

    public function index(IndexAttendanceIncidentRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        [$from, $to] = $request->range();

        $base = AttendanceDay::query()
            ->betweenDates($from, $to)
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
            ->when($filters['sub_department_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->whereIn('sub_department_id', SubDepartment::descendantIds($v)),
            ))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->ofDepartment($v),
            ))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->search($v),
            ));

        $days = (clone $base)
            ->with(self::RELATIONS)
            ->withIncident($filters['type'] ?? null)
            ->when($request->justifiedFilter() !== null, fn ($q) => $q->justified($request->justifiedFilter()))
            ->tap(fn (Builder $q) => $this->applySort($q, $request->sortBy(), $request->sortDir()))
            ->paginate($request->perPage())
            ->withQueryString();

        return AttendanceDayResource::collection($days)
            ->additional(['meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'totals' => $this->totals($base),
            ]]);
    }

    /**
     * Conteos del rango con los filtros de alcance (fechas, área, turno,
     * búsqueda) pero sin el de tipo ni el de justificación: son las tarjetas
     * que acompañan al listado.
     *
     * @return array<string, int>
     */
    private function totals(Builder $base): array
    {
        $row = (clone $base)->selectRaw(implode(', ', [
            'SUM(CASE WHEN status = ? AND justified_at IS NULL THEN 1 ELSE 0 END) AS late',
            'SUM(CASE WHEN status = ? AND justified_at IS NULL THEN 1 ELSE 0 END) AS absent',
            'SUM(CASE WHEN missing_out = 1 AND justified_at IS NULL THEN 1 ELSE 0 END) AS missing_out',
            'SUM(CASE WHEN break_exceeded = 1 AND justified_at IS NULL THEN 1 ELSE 0 END) AS break_exceeded',
            'SUM(CASE WHEN justified_at IS NOT NULL THEN 1 ELSE 0 END) AS justified',
        ]), [
            AttendanceCalendarService::STATUS_LATE,
            AttendanceCalendarService::STATUS_ABSENT,
        ])->first();

        return [
            'late' => (int) $row->late,
            'absent' => (int) $row->absent,
            'missing_out' => (int) $row->missing_out,
            'break_exceeded' => (int) $row->break_exceeded,
            'justified' => (int) $row->justified,
        ];
    }

    /**
     * El id desempata: sin él, dos renglones con la misma fecha cambian de
     * lugar entre páginas.
     */
    private function applySort(Builder $query, string $sortBy, string $sortDir): void
    {
        if ($sortBy === 'employee') {
            $query->orderBy(
                Employee::select('name')->whereColumn('employees.id', 'attendance_days.employee_id'),
                $sortDir,
            );
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        $query->orderBy('id', $sortDir);
    }

    /**
     * RH perdona la incidencia del día. El estatus calculado se conserva para
     * que conste lo que pasó; lo que cambia es cómo se cuenta.
     */
    public function justify(Request $request, AttendanceDay $attendanceDay): AttendanceDayResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        abort_if($attendanceDay->isLocked(), 422, 'El día está cerrado por nómina y ya no se puede modificar.');

        $attendanceDay->update([
            'justification' => $data['reason'],
            'justified_by' => $request->user()?->id,
            'justified_at' => now(),
        ]);

        return AttendanceDayResource::make($attendanceDay->refresh()->load(self::RELATIONS));
    }

    public function unjustify(AttendanceDay $attendanceDay): AttendanceDayResource
    {
        abort_if($attendanceDay->isLocked(), 422, 'El día está cerrado por nómina y ya no se puede modificar.');

        $attendanceDay->update([
            'justification' => null,
            'justified_by' => null,
            'justified_at' => null,
        ]);

        return AttendanceDayResource::make($attendanceDay->refresh()->load(self::RELATIONS));
    }
}
