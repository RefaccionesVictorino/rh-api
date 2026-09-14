<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    /** Relaciones que lleva el expediente completo de un empleado. */
    private const DETAIL_RELATIONS = [
        'subDepartment.department',
        'currentShiftAssignment.shift.days',
        'timeClockUser',
    ];

    /** Listado paginado con búsqueda y ordenamiento por columna. */
    public function index(IndexEmployeeRequest $request): AnonymousResourceCollection
    {
        $employees = Employee::query()
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

    public function show(Employee $employee): EmployeeResource
    {
        return EmployeeResource::make($employee->load(self::DETAIL_RELATIONS));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->payload());

        return EmployeeResource::make($employee->refresh()->load(self::DETAIL_RELATIONS));
    }
}
