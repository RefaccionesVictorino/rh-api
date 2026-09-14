<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexDepartmentRequest;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    /**
     * Listado paginado de áreas, con búsqueda y ordenamiento por columna.
     */
    public function index(IndexDepartmentRequest $request): AnonymousResourceCollection
    {
        $departments = Department::query()
            ->search($request->search())
            ->when($request->onlyActive(), fn ($query) => $query->active())
            ->when($request->withSubDepartments(), fn ($query) => $query->with([
                'subDepartments' => fn ($q) => $q->orderBy('name'),
            ]))
            ->with('manager')
            ->withCount(['subDepartments', 'employees'])
            ->orderBy($request->sortBy(), $request->sortDir())
            // Desempate estable: sin esto, dos registros con el mismo valor en la
            // columna ordenada pueden alternar de página entre peticiones.
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return DepartmentResource::collection($departments);
    }

    /**
     * Primer nivel del organigrama: las áreas activas con sus responsables y,
     * de cada una, solo las sub áreas raíz.
     *
     * La jerarquía puede anidarse a cualquier profundidad, así que el árbol no
     * se sirve completo: cada rama pide sus hijas al desplegarse
     * (sub-departments/{id}/children). Así ni el peso de la respuesta ni el de
     * la vista dependen de lo honda que sea la estructura.
     */
    public function chart(): AnonymousResourceCollection
    {
        $departments = Department::query()
            ->active()
            ->with([
                'manager',
                'subDepartments' => fn ($q) => $q->active()
                    ->roots()
                    ->with('manager')
                    ->withCount(['children', 'employees'])
                    ->orderBy('name'),
            ])
            ->withCount(['subDepartments', 'employees'])
            ->orderBy('name')
            ->get();

        return DepartmentResource::collection($departments);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create($request->validated());

        return DepartmentResource::make(
            // refresh: is_active lo pone la base por default, y sin recargar el
            // modelo recién creado lo reportaría como null.
            $department->refresh()->load('manager')->loadCount(['subDepartments', 'employees'])
        )->response()->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        return DepartmentResource::make(
            $department
                ->load(['manager', 'subDepartments' => fn ($q) => $q->orderBy('name')])
                ->loadCount(['subDepartments', 'employees'])
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        $department->update($request->validated());

        return DepartmentResource::make(
            $department->load('manager')->loadCount(['subDepartments', 'employees'])
        );
    }

    /**
     * Baja lógica. Se rechaza si el área todavía tiene sub áreas: la FK es
     * restrictOnDelete y el soft delete la esquivaría, dejando sub áreas
     * colgando de un área invisible.
     */
    public function destroy(Department $department): JsonResponse
    {
        if ($department->subDepartments()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el área porque tiene sub áreas asignadas.',
            ], 422);
        }

        $department->delete();

        return response()->json(status: 204);
    }
}
