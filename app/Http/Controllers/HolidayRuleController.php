<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHolidayRuleRequest;
use App\Http\Requests\UpdateHolidayRuleRequest;
use App\Http\Resources\HolidayRuleResource;
use App\Jobs\RecordAttendance;
use App\Models\HolidayRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HolidayRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'only_active' => ['nullable', 'boolean'],
        ]);

        $rules = HolidayRule::query()
            ->when($filters['only_active'] ?? false, fn ($query) => $query->active())
            ->orderBy('month')
            ->orderBy('day')
            ->orderBy('week_of_month')
            ->get();

        return HolidayRuleResource::collection($rules);
    }

    public function store(StoreHolidayRuleRequest $request): JsonResponse
    {
        $rule = HolidayRule::create($request->payload());

        $this->recordCurrentYear($rule);

        return HolidayRuleResource::make($rule->refresh())
            ->response()
            ->setStatusCode(201);
    }

    public function show(HolidayRule $holidayRule): HolidayRuleResource
    {
        return HolidayRuleResource::make($holidayRule);
    }

    public function update(UpdateHolidayRuleRequest $request, HolidayRule $holidayRule): HolidayRuleResource
    {
        $this->recordCurrentYear($holidayRule);

        $holidayRule->update($request->payload());

        $this->recordCurrentYear($holidayRule);

        return HolidayRuleResource::make($holidayRule->refresh());
    }

    /**
     * Desactivar la regla conserva las excepciones que RH capturó sobre ella;
     * borrarla las arrastra, de ahí que la baja sea el camino recomendado.
     */
    public function destroy(HolidayRule $holidayRule): JsonResponse
    {
        $holidayRule->delete();

        $this->recordCurrentYear($holidayRule);

        return response()->json(status: 204);
    }

    /**
     * Solo el año en curso: una regla capturada tarde suele ser por un festivo
     * de este año que ya pasó. Años anteriores se dejan como se evaluaron.
     */
    private function recordCurrentYear(HolidayRule $rule): void
    {
        $date = $rule->dateFor((int) now(config('time_clock.timezone'))->year);

        if ($date !== null) {
            RecordAttendance::forDate($date);
        }
    }
}
