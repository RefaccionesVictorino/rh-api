<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexLocationRequest;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function index(IndexLocationRequest $request): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->search($request->search())
            ->when($request->onlyActive(), fn ($query) => $query->active())
            ->withCount('devices')
            ->orderBy($request->sortBy(), $request->sortDir())
            // Desempate estable: sin esto, dos registros con el mismo valor en la
            // columna ordenada pueden alternar de página entre peticiones.
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return LocationResource::collection($locations);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = Location::create($request->validated());

        return LocationResource::make(
            // refresh: is_active lo pone la base por default, y sin recargar el
            // modelo recién creado lo reportaría como null.
            $location->refresh()->loadCount('devices')
        )->response()->setStatusCode(201);
    }

    public function show(Location $location): LocationResource
    {
        return LocationResource::make($location->loadCount(['devices', 'punches']));
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return LocationResource::make($location->loadCount('devices'));
    }

    /**
     * Baja lógica. Se rechaza si todavía tiene checadores asignados: la FK es
     * nullOnDelete y el soft delete la esquivaría, dejando equipos colgando de
     * una sucursal invisible.
     */
    public function destroy(Location $location): JsonResponse
    {
        if ($location->devices()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la sucursal porque tiene checadores asignados.',
            ], 422);
        }

        $location->delete();

        return response()->json(status: 204);
    }
}
