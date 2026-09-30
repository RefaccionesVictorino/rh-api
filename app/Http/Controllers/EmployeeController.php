<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexEmployeeRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeRegistrationService;
use App\Services\OrgChartScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    /** Relaciones que lleva el expediente completo de un empleado. */
    private const DETAIL_RELATIONS = [
        'subDepartment.department',
        'currentShiftAssignment.shift.days',
        'timeClockUser',
        'emergencyContacts',
    ];

    /**
     * Listado paginado con búsqueda y ordenamiento por columna. Un jefe de
     * área solo ve a quienes dependen de él.
     */
    public function index(IndexEmployeeRequest $request, OrgChartScope $scope): AnonymousResourceCollection
    {
        $employees = $scope->constrain(Employee::query(), $request->user())
            ->search($request->search())
            ->with('currentShiftAssignment.shift')
            ->orderBy($request->sortBy(), $request->sortDir())
            // Desempate estable: sin esto, dos registros con el mismo valor en la
            // columna ordenada pueden alternar de página entre peticiones.
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    /**
     * Alta del expediente junto con su usuario de checador.
     *
     * Responde 201 y no 202 aunque el checador sea asíncrono: el expediente sí
     * quedó creado al momento. Lo que queda encolado es su envío a las
     * terminales, y eso lo dice el mensaje.
     */
    public function store(
        StoreEmployeeRequest $request,
        EmployeeRegistrationService $registration,
    ): JsonResponse {
        ['employee' => $employee, 'devices' => $devices] = $registration->register(
            $request->payload(),
            $request->emergencyContacts(),
        );

        return EmployeeResource::make($employee->load(self::DETAIL_RELATIONS))
            ->additional(['message' => $registration->syncMessage($devices)])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        return EmployeeResource::make($employee->load(self::DETAIL_RELATIONS));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        DB::transaction(function () use ($request, $employee): void {
            $employee->update($request->payload());

            $contacts = $request->emergencyContacts();
            if ($contacts !== null) {
                $employee->syncEmergencyContacts($contacts);
            }
        });

        return EmployeeResource::make($employee->refresh()->load(self::DETAIL_RELATIONS));
    }
}
