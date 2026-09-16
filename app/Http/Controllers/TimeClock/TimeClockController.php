<?php

namespace App\Http\Controllers\TimeClock;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteTimeClockCommandRequest;
use App\Http\Requests\StoreTimeClockUserRequest;
use App\Http\Requests\UpdateTimeClockDeviceRequest;
use App\Models\AttendancePunch;
use App\Models\EmployeeShift;
use App\Models\SubDepartment;
use App\Models\TimeClockCommand;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use App\Services\TimeClock\ZkCommandBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * API de administración del checador para el frontend.
 *
 * Nada aquí habla con el equipo de forma directa: los cambios se encolan y el
 * terminal los aplica en su siguiente sondeo. Por eso las respuestas de
 * escritura devuelven 202 (aceptado) en lugar de 200.
 */
class TimeClockController extends Controller
{
    public function __construct(private readonly ZkCommandBuilder $commands) {}

    /** Terminales dadas de alta y su estado de conexión. */
    public function devices(): JsonResponse
    {
        $devices = TimeClockDevice::withCount('punches')
            ->with('branch:id,name,code')
            ->orderBy('name')
            ->get()
            ->map(fn (TimeClockDevice $device) => $this->devicePayload($device));

        return response()->json(['data' => $devices]);
    }

    /** Cambiar la sucursal solo afecta a las checadas que se registren después. */
    public function updateDevice(UpdateTimeClockDeviceRequest $request, TimeClockDevice $device): JsonResponse
    {
        $device->update($request->validated());

        return response()->json([
            'data' => $this->devicePayload(
                $device->load('branch:id,name,code')->loadCount('punches')
            ),
        ]);
    }

    /** Checadas con filtros de fecha, empleado, PIN, equipo, tipo, turno y área. */
    public function punches(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'pin' => ['nullable', 'string', 'max:20'],
            'device_id' => ['nullable', 'integer', 'exists:time_clock_devices,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'punch_type' => ['nullable', 'integer', Rule::in(array_keys(AttendancePunch::TYPES))],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sub_department_id' => ['nullable', 'integer', 'exists:sub_departments,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $punches = AttendancePunch::query()
            ->with([
                'device:id,name,serial_number',
                'location:id,name,code',
                'employee:id,name,last_name,second_last_name,sub_department_id',
                'employee.subDepartment:id,name,department_id',
                'employee.subDepartment.department:id,name,color',
            ])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('punched_at', '>=', $v.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('punched_at', '<=', $v.' 23:59:59'))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['pin'] ?? null, fn ($q, $v) => $q->where('pin', $v))
            ->when($filters['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when(isset($filters['punch_type']), fn ($q) => $q->where('punch_type', $filters['punch_type']))
            ->when(
                $filters['sub_department_id'] ?? null,
                fn ($q, $v) => $q->whereHas(
                    'employee',
                    fn (Builder $e) => $e->whereIn('sub_department_id', SubDepartment::descendantIds($v)),
                ),
            )
            ->when(
                $filters['department_id'] ?? null,
                fn ($q, $v) => $q->whereHas('employee', fn (Builder $e) => $e->ofDepartment($v)),
            )
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->search($v),
            ))
            // El turno se resuelve contra la fecha de la checada, no contra hoy:
            // una checada vieja pertenece al turno que el empleado tenía ese día.
            ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->whereExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('employee_shifts')
                    ->whereColumn('employee_shifts.employee_id', 'attendance_punches.employee_id')
                    ->where('employee_shifts.shift_id', $v)
                    // DATE() en ambos lados: SQLite guarda la fecha con hora y
                    // la comparación cruda sería entre cadenas de distinto largo.
                    ->whereRaw('DATE(employee_shifts.starts_on) <= DATE(attendance_punches.punched_at)')
                    ->where(fn ($w) => $w
                        ->whereNull('employee_shifts.ends_on')
                        ->orWhereRaw('DATE(employee_shifts.ends_on) >= DATE(attendance_punches.punched_at)')),
            ))
            ->atLocation($filters['location_id'] ?? null)
            ->orderByDesc('punched_at')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        $shifts = $this->shiftsForPunches($punches->getCollection());

        $punches->getCollection()->transform(function (AttendancePunch $punch) use ($shifts) {
            $subDepartment = $punch->employee?->subDepartment;
            $shift = $shifts->get($punch->employee_id.'|'.$punch->punched_at->toDateString());

            return [
                'id' => $punch->id,
                'employee_id' => $punch->employee_id,
                'employee_name' => $punch->employee?->full_name,
                'pin' => $punch->pin,
                'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                'punch_type' => $punch->punch_type,
                'punch_type_label' => $punch->type_label,
                'verify_mode_label' => $punch->verify_mode_label,
                'source' => $punch->source,
                'device' => $punch->device?->name,
                'location_id' => $punch->location_id,
                'location' => $punch->location?->name,
                'shift' => $shift === null ? null : [
                    'id' => $shift->shift_id,
                    'name' => $shift->shift?->name,
                    'code' => $shift->shift?->code,
                    'start_time' => $shift->expected_start,
                    'end_time' => $shift->expected_end,
                ],
                'sub_department' => $subDepartment === null ? null : [
                    'id' => $subDepartment->id,
                    'name' => $subDepartment->name,
                ],
                'department' => $subDepartment?->department === null ? null : [
                    'id' => $subDepartment->department->id,
                    'name' => $subDepartment->department->name,
                    'color' => $subDepartment->department->color,
                ],
            ];
        });

        return response()->json($punches);
    }

    /**
     * Turno vigente de cada empleado en la fecha de su checada, indexado por
     * "empleado|fecha".
     *
     * Se resuelve en una sola consulta sobre los empleados y fechas de la
     * página, en vez de una por renglón. Cada asignación trae el horario del
     * día de la semana que corresponde, que es lo que se muestra como horario.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return Collection<string, EmployeeShift>
     */
    private function shiftsForPunches($punches): Collection
    {
        $employeeIds = $punches->pluck('employee_id')->filter()->unique();

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $dates = $punches->map(fn (AttendancePunch $punch) => $punch->punched_at->toDateString())->unique();

        $assignments = EmployeeShift::query()
            ->with('shift.days')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('starts_on', '<=', $dates->max())
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $dates->min()))
            ->get();

        return $punches->mapWithKeys(function (AttendancePunch $punch) use ($assignments) {
            $date = $punch->punched_at->copy()->startOfDay();

            $assignment = $assignments->first(
                fn (EmployeeShift $a) => $a->employee_id === $punch->employee_id && $a->coversDate($date),
            );

            if ($assignment !== null) {
                // El horario esperado depende del día de la semana de la checada.
                $day = $assignment->shift?->dayFor((int) $date->dayOfWeek);
                $assignment->expected_start = $day?->is_rest_day ? null : $day?->start_time;
                $assignment->expected_end = $day?->is_rest_day ? null : $day?->end_time;
            }

            return [$punch->employee_id.'|'.$date->toDateString() => $assignment];
        })->filter();
    }

    /** Catálogo de usuarios del checador. */
    public function users(): JsonResponse
    {
        return response()->json([
            'data' => TimeClockUser::with('employee:id,name,last_name,second_last_name')
                ->orderBy('name')
                ->get()
                ->map(fn (TimeClockUser $user) => $this->userPayload($user)),
        ]);
    }

    /**
     * Alta de usuario: se guarda local y se encola hacia los equipos activos.
     *
     * El rostro no se puede enviar desde aquí; el empleado debe enrolarlo
     * una vez frente a cada terminal donde vaya a usarlo.
     */
    public function storeUser(StoreTimeClockUserRequest $request): JsonResponse
    {
        $user = TimeClockUser::create($request->validated());

        $queued = $this->forEachActiveDevice(
            fn (TimeClockDevice $device) => $this->commands->upsertUser($device, $user)
        );

        return response()->json([
            'data' => $this->userPayload($user->load('employee')),
            'message' => "Usuario creado y enviado a {$queued} terminal(es). "
                .'Falta enrolar el rostro en el equipo.',
        ], 202);
    }

    /** Baja de usuario en el sistema y en los equipos. */
    public function destroyUser(TimeClockUser $user): JsonResponse
    {
        $pin = $user->pin;

        $queued = $this->forEachActiveDevice(
            fn (TimeClockDevice $device) => $this->commands->deleteUser($device, $pin)
        );

        $user->delete();

        return response()->json([
            'message' => "Baja enviada a {$queued} terminal(es).",
        ], 202);
    }

    /** Comandos de operación sobre un equipo, expuestos por nombre. */
    public function execute(ExecuteTimeClockCommandRequest $request, TimeClockDevice $device): JsonResponse
    {
        $data = $request->validated();

        $command = match ($data['action']) {
            'sync_time' => $this->commands->syncTime($device),
            'reboot' => $this->commands->reboot($device),
            'unlock_door' => $this->commands->unlockDoor($device, $data['seconds'] ?? 5),
            'query_users' => $this->commands->queryUsers($device),
            'clear_log' => $this->commands->clearLog($device),
            'message' => $this->commands->message($device, $data['text'] ?? '', $data['seconds'] ?? 10),
            'query_punches' => $this->commands->queryPunches(
                $device,
                ($data['from'] ?? now()->subDay()->toDateString()).' 00:00:00',
                ($data['to'] ?? now()->toDateString()).' 23:59:59'
            ),
        };

        return response()->json([
            'data' => $this->commandPayload($command),
            'message' => 'Comando encolado; el equipo lo aplicará en su próximo sondeo ('
                .config('time_clock.delay').' s aprox.).',
        ], 202);
    }

    /** Historial de comandos para diagnosticar qué aplicó el equipo. */
    public function commands(TimeClockDevice $device): JsonResponse
    {
        $commands = $device->commands()
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (TimeClockCommand $command) => $this->commandPayload($command));

        return response()->json(['data' => $commands]);
    }

    /** Encola una acción en todos los equipos activos. */
    private function forEachActiveDevice(callable $action): int
    {
        $devices = TimeClockDevice::where('is_active', true)->get();

        $devices->each($action);

        return $devices->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function devicePayload(TimeClockDevice $device): array
    {
        return [
            'id' => $device->id,
            'serial_number' => $device->serial_number,
            'name' => $device->name,
            'location_id' => $device->location_id,
            'location_name' => $device->branch?->name,
            'location' => $device->location,
            'ip_address' => $device->ip_address,
            'is_active' => $device->is_active,
            'is_online' => $device->isOnline(),
            'last_seen_at' => $device->last_seen_at,
            'punches_count' => $device->punches_count,
            'pending_commands_count' => $device->commands()->pending()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(TimeClockUser $user): array
    {
        return [
            'id' => $user->id,
            'pin' => $user->pin,
            'name' => $user->name,
            'card_number' => $user->card_number,
            'privilege' => $user->privilege,
            'is_admin' => $user->isAdmin(),
            'employee_id' => $user->employee_id,
            'employee_name' => $user->employee?->full_name,
            'is_active' => $user->is_active,
            'synced_at' => $user->synced_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commandPayload(TimeClockCommand $command): array
    {
        return [
            'id' => $command->id,
            'type' => $command->type,
            'command' => $command->command,
            'status' => $command->status,
            'result_code' => $command->result_code,
            'sent_at' => $command->sent_at,
            'confirmed_at' => $command->confirmed_at,
        ];
    }
}
