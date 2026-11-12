<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\TimeClockDevice;
use App\Models\WorkerAccount;
use App\Models\WorkerDevice;
use App\Notifications\PunchMissing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MissingPunchNotificationTest extends TestCase
{
    /** Jueves. */
    private const DATE = '2026-09-10';

    private function at(string $time, string $date = self::DATE): void
    {
        $this->travelTo(now()->parse("{$date} {$time}", config('time_clock.timezone')));
    }

    private function onlineDevice(): TimeClockDevice
    {
        return TimeClockDevice::create([
            'serial_number' => 'NYU7253800622',
            'name' => 'Recepción',
            'last_seen_at' => now(),
        ]);
    }

    /** Lunes a viernes; tolerancia de 10 y falta a los 30 minutos. */
    private function employeeWithShift(string $start = '09:00', string $end = '18:00', ?int $absenceAfter = 30): Employee
    {
        $employee = Employee::factory()->create(['hire_date' => '2024-01-15']);

        $shift = Shift::create([
            'name' => 'Turno '.$employee->id,
            'tolerance_minutes' => 10,
            'absence_after_minutes' => $absenceAfter,
        ]);
        $shift->syncDays(collect(range(1, 5))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
        ])->all());

        $employee->shiftAssignments()->create(['shift_id' => $shift->id, 'starts_on' => '2024-01-15']);

        return $employee;
    }

    private function accountWithPhone(Employee $employee, ?string $pushToken = 'ExponentPushToken[abc]'): WorkerAccount
    {
        $account = WorkerAccount::create([
            'employee_id' => $employee->id,
            'nip' => '482915',
            'activated_at' => now(),
        ]);

        WorkerDevice::create([
            'worker_account_id' => $account->id,
            'personal_access_token_id' => $account->createToken('app')->accessToken->id,
            'installation_id' => 'install-'.$employee->id,
            'platform' => 'android',
            'expo_push_token' => $pushToken,
        ]);

        return $account;
    }

    public function test_notifies_the_worker_once_the_absence_threshold_passed(): void
    {
        Notification::fake();
        $this->at('09:40');
        $this->onlineDevice();

        $account = $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')
            ->expectsOutputToContain('1 trabajador(es)')
            ->assertSuccessful();

        Notification::assertSentTo($account, PunchMissing::class, function (PunchMissing $notification) use ($account) {
            $message = $notification->toExpo($account);

            return $notification->date === self::DATE
                && str_contains($message['body'], 'No se registró tu asistencia del 10/09/2026')
                && str_contains($message['body'], 'contacta a RH');
        });
    }

    public function test_does_not_notify_before_the_threshold_plus_grace(): void
    {
        Notification::fake();
        $this->at('09:34');
        $this->onlineDevice();

        $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_falls_back_to_the_tolerance_when_the_shift_has_no_absence_threshold(): void
    {
        Notification::fake();
        $this->at('09:16');
        $this->onlineDevice();

        $account = $this->accountWithPhone($this->employeeWithShift(absenceAfter: null));

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertSentTo($account, PunchMissing::class);
    }

    public function test_does_not_notify_while_a_device_is_offline(): void
    {
        Notification::fake();
        $this->at('09:40');
        $this->onlineDevice();
        TimeClockDevice::create([
            'serial_number' => 'OFFLINE1',
            'name' => 'Almacén',
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')
            ->expectsOutputToContain('sin señal')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_ignores_inactive_devices_when_checking_sync(): void
    {
        Notification::fake();
        $this->at('09:40');
        $this->onlineDevice();
        TimeClockDevice::create([
            'serial_number' => 'RETIRED1',
            'name' => 'Viejo',
            'is_active' => false,
            'last_seen_at' => now()->subDays(30),
        ]);

        $account = $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertSentTo($account, PunchMissing::class);
    }

    public function test_does_not_notify_without_any_active_device(): void
    {
        Notification::fake();
        $this->at('09:40');

        $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_does_not_notify_a_worker_who_punched_in(): void
    {
        Notification::fake();
        $this->at('09:40');
        $this->onlineDevice();

        $employee = $this->employeeWithShift();
        $this->accountWithPhone($employee);

        AttendancePunch::create([
            'employee_id' => $employee->id,
            'pin' => $employee->rfc,
            'punched_at' => self::DATE.' 09:20:00',
            'punch_type' => AttendancePunch::TYPE_IN,
            'source' => 'device',
        ]);

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_does_not_notify_after_the_shift_ended(): void
    {
        Notification::fake();
        $this->at('18:10');
        $this->onlineDevice();

        $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_does_not_notify_on_a_rest_day(): void
    {
        Notification::fake();
        $this->at('09:40', '2026-09-12');
        $this->onlineDevice();

        $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_night_shift_is_evaluated_against_the_day_it_started(): void
    {
        Notification::fake();
        $this->at('00:30', '2026-09-11');
        $this->onlineDevice();

        $account = $this->accountWithPhone($this->employeeWithShift('22:00', '06:00'));

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        Notification::assertSentToTimes($account, PunchMissing::class, 1);
        Notification::assertSentTo($account, PunchMissing::class, fn (PunchMissing $n) => $n->date === self::DATE);
    }

    public function test_skips_workers_without_a_push_token_or_without_an_account(): void
    {
        Notification::fake();
        $this->at('09:40');
        $this->onlineDevice();

        $this->accountWithPhone($this->employeeWithShift(), pushToken: null);
        $this->employeeWithShift();

        $this->artisan('attendance:notify-missing')
            ->expectsOutputToContain('0 trabajador(es)')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_notifies_only_once_per_shift_day(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        $this->at('09:40');
        $device = $this->onlineDevice();

        $account = $this->accountWithPhone($this->employeeWithShift());

        $this->artisan('attendance:notify-missing')->assertSuccessful();

        $this->at('10:10');
        $device->update(['last_seen_at' => now()]);
        $this->artisan('attendance:notify-missing')
            ->expectsOutputToContain('0 trabajador(es)')
            ->assertSuccessful();

        $this->assertSame(1, $account->notifications()->count());
        Http::assertSentCount(1);
    }
}
