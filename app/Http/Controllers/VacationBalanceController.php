<?php

namespace App\Http\Controllers;

use App\Http\Resources\VacationPeriodResource;
use App\Models\Employee;
use App\Models\VacationEntitlement;
use App\Models\VacationPeriod;
use App\Services\VacationPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VacationBalanceController extends Controller
{
    public function __construct(private readonly VacationPeriodService $periods) {}

    /**
     * Saldo de vacaciones del empleado. Los periodos faltantes se generan al
     * consultarlos, así que no hace falta una tarea programada.
     */
    public function show(Employee $employee): JsonResponse
    {
        $summary = $this->periods->summary($employee);

        return response()->json([
            'data' => [
                'employee_id' => $employee->id,
                'hire_date' => $employee->hire_date?->toDateString(),
                'years_of_service' => $summary['years_of_service'],
                'next_entitlement_days' => $summary['next_entitlement_days'],
                'available_days' => $summary['available_days'],
                'expired_days' => $summary['expired_days'],
                'taken_days' => $summary['taken_days'],
                'granted_days' => $summary['granted_days'],
                'periods' => VacationPeriodResource::collection($summary['periods']),
            ],
        ]);
    }

    /**
     * Ajuste manual de un periodo: días de más por acuerdo o corrección de
     * saldo. No toca los días que marca el tabulador.
     */
    public function adjust(Request $request, VacationPeriod $vacationPeriod): VacationPeriodResource
    {
        $data = $request->validate([
            'adjustment_days' => ['required', 'integer', 'between:-365,365'],
            'adjustment_reason' => ['required', 'string', 'max:255'],
        ]);

        $vacationPeriod->update($data);

        return VacationPeriodResource::make($vacationPeriod->refresh());
    }

    /** Tabulador vigente, para mostrarlo en la pantalla de configuración. */
    public function entitlements(): JsonResponse
    {
        return response()->json([
            'data' => VacationEntitlement::orderBy('from_year')->get(),
        ]);
    }

    /**
     * Cambia el tabulador completo. Se reemplaza en bloque porque los rangos
     * deben quedar contiguos y sin huecos.
     */
    public function updateEntitlements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entitlements' => ['required', 'array', 'min:1'],
            'entitlements.*.from_year' => ['required', 'integer', 'min:1', 'max:100', 'distinct'],
            'entitlements.*.to_year' => ['nullable', 'integer', 'min:1', 'max:100'],
            'entitlements.*.days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $rows = collect($data['entitlements'])->sortBy('from_year')->values();

        foreach ($rows as $index => $row) {
            if ($row['to_year'] !== null && $row['to_year'] < $row['from_year']) {
                throw ValidationException::withMessages([
                    "entitlements.{$index}.to_year" => 'El año final no puede ser menor al inicial.',
                ]);
            }

            // Solo el último renglón queda abierto; si no, la antigüedad alta
            // podría caer en dos rangos a la vez.
            if ($row['to_year'] === null && $index !== $rows->count() - 1) {
                throw ValidationException::withMessages([
                    "entitlements.{$index}.to_year" => 'Solo el último renglón puede quedar sin año final.',
                ]);
            }
        }

        DB::transaction(function () use ($rows): void {
            VacationEntitlement::query()->delete();

            foreach ($rows as $row) {
                VacationEntitlement::create($row);
            }
        });

        return $this->entitlements();
    }
}
