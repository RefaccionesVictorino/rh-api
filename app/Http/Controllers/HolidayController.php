<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Services\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HolidayController extends Controller
{
    public function __construct(private readonly HolidayCalendar $calendar) {}

    /**
     * Calendario del año, con los días que genera el catálogo y los capturados
     * a mano ya combinados. Sin filtro devuelve el año en curso.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        $holidays = isset($filters['from'])
            ? $this->calendar->between(
                CarbonImmutable::parse($filters['from']),
                CarbonImmutable::parse($filters['to']),
            )
            : $this->calendar->forYear($filters['year'] ?? now()->year);

        return HolidayResource::collection($holidays->values());
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::create($request->payload());

        return HolidayResource::make($holiday->refresh())
            ->response()
            ->setStatusCode(201);
    }

    public function show(Holiday $holiday): HolidayResource
    {
        return HolidayResource::make($holiday);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): HolidayResource
    {
        $holiday->update($request->payload());

        return HolidayResource::make($holiday->refresh());
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(status: 204);
    }
}
