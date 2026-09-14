<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexSubDepartmentRequest;
use App\Http\Requests\StoreSubDepartmentRequest;
use App\Http\Requests\UpdateSubDepartmentRequest;
use App\Http\Resources\EmployeeSummaryResource;
use App\Http\Resources\SubDepartmentResource;
use App\Models\SubDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubDepartmentController extends Controller
{
    /**
     * Listado paginado de sub áreas, filtrable por área.
     */
    public function index(IndexSubDepartmentRequest $request): AnonymousResourceCollection
    {
        $subDepartments = SubDepartment::query()
            ->search($request->search())
            ->ofDepartment($request->departmentId())
            ->when($request->onlyActive(), fn ($query) => $query->active())
            ->with(['department', 'manager'])
            ->withCount(['children', 'employees'])
            ->orderBy($request->sortBy(), $request->sortDir())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return SubDepartmentResource::collection($subDepartments);
    }

    /**
     * Integrantes de una sub área, para desplegarlos en el organigrama. Va
     * aparte del show(): la plantilla completa solo se pide cuando alguien
     * expande esa rama, no al cargar el árbol entero.
     */
    public function members(SubDepartment $subDepartment): AnonymousResourceCollection
    {
        $employees = $subDepartment->employees()
            ->orderBy('name')
            ->orderBy('last_name')
            ->get();

        return EmployeeSummaryResource::collection($employees);
    }

    /**
     * Sub áreas que cuelgan de esta, un solo nivel.
     *
     * El organigrama las pide al desplegar la rama, no al cargar el árbol: así
     * la profundidad de la jerarquía no encarece la vista inicial.
     */
    public function children(SubDepartment $subDepartment): AnonymousResourceCollection
    {
        $children = $subDepartment->children()
            ->with('manager')
            ->withCount(['children', 'employees'])
            ->orderBy('name')
            ->get();

        return SubDepartmentResource::collection($children);
    }

    public function store(StoreSubDepartmentRequest $request): JsonResponse
    {
        $subDepartment = SubDepartment::create($request->validated());

        return SubDepartmentResource::make(
            // refresh: is_active lo pone la base por default, y sin recargar el
            // modelo recién creado lo reportaría como null.
            $subDepartment->refresh()->load(['department', 'manager'])->loadCount(['children', 'employees'])
        )->response()->setStatusCode(201);
    }

    public function show(SubDepartment $subDepartment): SubDepartmentResource
    {
        return SubDepartmentResource::make(
            $subDepartment->load(['department', 'manager'])->loadCount(['children', 'employees'])
        );
    }

    public function update(
        UpdateSubDepartmentRequest $request,
        SubDepartment $subDepartment
    ): SubDepartmentResource {
        $subDepartment->update($request->validated());

        return SubDepartmentResource::make(
            $subDepartment->load(['department', 'manager'])->loadCount(['children', 'employees'])
        );
    }

    /**
     * Baja lógica. Se rechaza si la sub área todavía tiene hijas o empleados: la
     * FK es restrictOnDelete y el soft delete la esquivaría, dejando la rama
     * colgando de una sub área invisible.
     */
    public function destroy(SubDepartment $subDepartment): JsonResponse
    {
        if ($subDepartment->children()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la sub área porque tiene sub áreas dentro.',
            ], 422);
        }

        if ($subDepartment->employees()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la sub área porque tiene empleados asignados.',
            ], 422);
        }

        $subDepartment->delete();

        return response()->json(status: 204);
    }
}
