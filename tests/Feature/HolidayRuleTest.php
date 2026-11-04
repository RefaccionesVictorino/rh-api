<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\HolidayRule;
use App\Models\User;
use App\Services\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HolidayRuleTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUserWith(string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        Sanctum::actingAs($user);

        return $user;
    }

    private function newYearRule(): HolidayRule
    {
        return HolidayRule::create([
            'name' => 'Año Nuevo',
            'rule_type' => HolidayRule::FIXED,
            'month' => 1,
            'day' => 1,
            'is_mandatory' => true,
        ]);
    }

    /**
     * @return array<string, Holiday>
     */
    private function calendarFor(int $year): array
    {
        return app(HolidayCalendar::class)->forYear($year)->all();
    }

    public function test_a_fixed_rule_generates_the_date_for_any_year(): void
    {
        $this->newYearRule();

        foreach ([2027, 2030, 2045] as $year) {
            $calendar = $this->calendarFor($year);

            $this->assertArrayHasKey("{$year}-01-01", $calendar);
            $this->assertSame('Año Nuevo', $calendar["{$year}-01-01"]->name);
            $this->assertNull($calendar["{$year}-01-01"]->id, 'El día generado no se persiste.');
        }
    }

    public function test_a_nth_weekday_rule_follows_the_movable_holiday(): void
    {
        HolidayRule::create([
            'name' => 'Aniversario de la Constitución',
            'rule_type' => HolidayRule::NTH_WEEKDAY,
            'month' => 2,
            'weekday' => 1,
            'week_of_month' => 1,
        ]);

        // Primer lunes de febrero en cada año.
        $this->assertArrayHasKey('2027-02-01', $this->calendarFor(2027));
        $this->assertArrayHasKey('2028-02-07', $this->calendarFor(2028));
        $this->assertArrayHasKey('2029-02-05', $this->calendarFor(2029));
    }

    public function test_a_rule_outside_its_years_does_not_generate_dates(): void
    {
        HolidayRule::create([
            'name' => 'Aniversario de la empresa',
            'month' => 6,
            'day' => 15,
            'starts_year' => 2027,
            'ends_year' => 2028,
        ]);

        $this->assertArrayNotHasKey('2026-06-15', $this->calendarFor(2026));
        $this->assertArrayHasKey('2027-06-15', $this->calendarFor(2027));
        $this->assertArrayHasKey('2028-06-15', $this->calendarFor(2028));
        $this->assertArrayNotHasKey('2029-06-15', $this->calendarFor(2029));
    }

    public function test_an_inactive_rule_stops_generating_dates(): void
    {
        $rule = $this->newYearRule();

        $this->assertArrayHasKey('2030-01-01', $this->calendarFor(2030));

        $rule->update(['is_active' => false]);

        $this->assertArrayNotHasKey('2030-01-01', $this->calendarFor(2030));
    }

    public function test_an_exception_replaces_the_rule_for_that_year_only(): void
    {
        $rule = $this->newYearRule();

        Holiday::create([
            'holiday_rule_id' => $rule->id,
            'date' => '2027-01-02',
            'name' => 'Año Nuevo recorrido',
            'observance' => Holiday::REST,
        ]);

        $calendar = $this->calendarFor(2027);

        $this->assertArrayHasKey('2027-01-02', $calendar);
        $this->assertArrayNotHasKey('2027-01-01', $calendar, 'La regla no debe duplicar el festivo movido.');

        // Los demás años siguen saliendo de la regla.
        $this->assertArrayHasKey('2028-01-01', $this->calendarFor(2028));
    }

    public function test_an_exception_can_change_the_observance_of_one_year(): void
    {
        $rule = $this->newYearRule();

        Holiday::create([
            'holiday_rule_id' => $rule->id,
            'date' => '2027-01-01',
            'name' => 'Año Nuevo con guardia',
            'observance' => Holiday::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);

        $day = $this->calendarFor(2027)['2027-01-01'];

        $this->assertSame(Holiday::SPECIAL_HOURS, $day->observance);
        $this->assertSame(240, $day->work_minutes);
        $this->assertNotNull($day->id);
    }

    public function test_company_dates_without_a_rule_appear_in_the_calendar(): void
    {
        $this->newYearRule();

        Holiday::create([
            'date' => '2027-07-15',
            'name' => 'Inventario anual',
            'observance' => Holiday::REST,
        ]);

        $calendar = $this->calendarFor(2027);

        $this->assertArrayHasKey('2027-07-15', $calendar);
        $this->assertArrayHasKey('2027-01-01', $calendar);
    }

    public function test_the_calendar_spans_a_range_across_two_years(): void
    {
        $this->newYearRule();

        $holidays = app(HolidayCalendar::class)->between(
            CarbonImmutable::parse('2027-12-20'),
            CarbonImmutable::parse('2028-01-10'),
        );

        $this->assertTrue($holidays->has('2028-01-01'));
        $this->assertFalse($holidays->has('2027-01-01'));
    }

    public function test_february_29_only_generates_on_leap_years(): void
    {
        HolidayRule::create(['name' => 'Día bisiesto', 'month' => 2, 'day' => 29]);

        $this->assertArrayHasKey('2028-02-29', $this->calendarFor(2028));
        $this->assertArrayNotHasKey('2029-02-29', $this->calendarFor(2029));
    }

    public function test_creates_a_rule_through_the_api(): void
    {
        $this->actingAsUserWith('festivos.crear');

        $this->postJson('/api/holiday-rules', [
            'name' => 'Aniversario de la empresa',
            'rule_type' => HolidayRule::FIXED,
            'month' => 6,
            'day' => 15,
            'observance' => HolidayRule::SPECIAL_HOURS,
            'start_time' => '09:00',
            'end_time' => '14:00',
        ])->assertCreated()
            ->assertJsonPath('data.rule_type_label', 'Fecha fija')
            ->assertJsonPath('data.next_dates.0', now()->year.'-06-15');
    }

    public function test_a_nth_weekday_rule_requires_the_weekday_and_week(): void
    {
        $this->actingAsUserWith('festivos.crear');

        $this->postJson('/api/holiday-rules', [
            'name' => 'Mal definida',
            'rule_type' => HolidayRule::NTH_WEEKDAY,
            'month' => 3,
            'observance' => HolidayRule::REST,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['weekday', 'week_of_month']);
    }

    public function test_switching_a_rule_to_nth_weekday_requires_its_fields(): void
    {
        $this->actingAsUserWith('festivos.crear', 'festivos.editar');

        $rule = $this->newYearRule();

        $this->patchJson("/api/holiday-rules/{$rule->id}", [
            'rule_type' => HolidayRule::NTH_WEEKDAY,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['weekday']);

        $this->patchJson("/api/holiday-rules/{$rule->id}", [
            'rule_type' => HolidayRule::NTH_WEEKDAY,
            'weekday' => 1,
            'week_of_month' => 2,
        ])->assertOk()
            ->assertJsonPath('data.weekday_name', 'Lunes')
            ->assertJsonPath('data.day', null);
    }

    public function test_switching_to_special_hours_requires_a_schedule(): void
    {
        $this->actingAsUserWith('festivos.crear', 'festivos.editar');

        $rule = $this->newYearRule();

        $this->patchJson("/api/holiday-rules/{$rule->id}", [
            'observance' => HolidayRule::SPECIAL_HOURS,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    public function test_lists_rules_and_requires_permission(): void
    {
        $this->actingAsUserWith('festivos.ver');

        $this->newYearRule();

        $this->getJson('/api/holiday-rules')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/holiday-rules', [
            'name' => 'x', 'rule_type' => HolidayRule::FIXED, 'month' => 1, 'day' => 1,
            'observance' => HolidayRule::REST,
        ])->assertForbidden();
    }
}
