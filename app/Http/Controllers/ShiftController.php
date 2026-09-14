<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexShiftRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    /**
     * Listado paginado de turnos con su horario semanal.
     *
     * Los días viajan siempre: son 7 renglones por turno y el listado los
     * necesita para pintar el resumen de horario sin una petición por fila.
     */
    public function index(IndexShiftRequest $request): AnonymousResourceCollection
    {
        $shifts = Shift::query()
            ->search($request->search())
            ->when($request->onlyActive(), fn ($query) => $query->active())
            ->with('days')
            ->withCount('currentAssignments')
            ->orderBy($request->sortBy(), $request->sortDir())
            // Desempate estable entre páginas.
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ShiftResource::collection($shifts);
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        $shift = DB::transaction(function () use ($request): Shift {
            $shift = Shift::create($request->safe()->except('days'));
            $shift->syncDays($request->validated('days'));

            return $shift;
        });

        return ShiftResource::make(
            // refresh: los defaults de la base (tolerancia, is_active) no
            // están en el modelo recién creado cuando no vinieron en la petición.
            $shift->refresh()->load('days')
        )->response()->setStatusCode(201);
    }

    public function show(Shift $shift): ShiftResource
    {
        return ShiftResource::make($shift->load('days')->loadCount('currentAssignments'));
    }

    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        DB::transaction(function () use ($request, $shift): void {
            $shift->update($request->safe()->except('days'));

            if ($request->has('days')) {
                $shift->syncDays($request->validated('days'));
            }
        });

        return ShiftResource::make($shift->refresh()->load('days'));
    }

    /**
     * Baja lógica. Los días se conservan junto con el turno para que la
     * historia de asistencia calculada con él siga siendo explicable.
     *
     * Se rechaza si hay empleados en el turno hoy o programados para entrar
     * después: se quedarían sin horario esperado. Las asignaciones ya
     * terminadas no estorban; son historia.
     */
    public function destroy(Shift $shift): JsonResponse
    {
        if ($shift->assignments()->activeOrFuture()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el turno porque tiene empleados asignados.',
            ], 422);
        }

        $shift->delete();

        return response()->json(status: 204);
    }
}
