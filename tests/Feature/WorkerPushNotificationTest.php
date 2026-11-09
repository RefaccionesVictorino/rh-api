<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\TimeClockUser;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\WorkerAccount;
use App\Models\WorkerDevice;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\PunchRegistered;
use App\Notifications\VacationRequestResolved;
use App\Services\VacationRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WorkerPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRequest(): VacationRequest
    {
        $employee = Employee::factory()->create();

        return $employee->vacationRequests()->create([
            'starts_on' => '2026-12-07',
            'ends_on' => '2026-12-11',
            'requested_days' => 5,
        ]);
    }

    private function account(VacationRequest $request): WorkerAccount
    {
        return WorkerAccount::create([
            'employee_id' => $request->employee_id,
            'nip' => '482915',
            'activated_at' => now(),
        ]);
    }

    private function device(WorkerAccount $account, ?string $pushToken, string $installationId = 'install-1'): WorkerDevice
    {
        return WorkerDevice::create([
            'worker_account_id' => $account->id,
            'personal_access_token_id' => $account->createToken('app')->accessToken->id,
            'installation_id' => $installationId,
            'platform' => 'android',
            'expo_push_token' => $pushToken,
        ]);
    }

    public function test_rejecting_notifies_the_worker_account(): void
    {
        Notification::fake();

        $request = $this->pendingRequest();
        $account = $this->account($request);

        app(VacationRequestService::class)->reject($request, null, 'Cobertura de la sucursal');

        Notification::assertSentTo($account, VacationRequestResolved::class, function (VacationRequestResolved $notification) use ($account) {
            $message = $notification->toExpo($account);

            return $notification->via($account) === [ExpoPushChannel::class]
                && str_contains($message['body'], 'rechazada')
                && str_contains($message['body'], 'Cobertura de la sucursal');
        });
    }

    public function test_approving_notifies_the_worker_account(): void
    {
        Notification::fake();
        Queue::fake();

        $request = $this->pendingRequest();
        $account = $this->account($request);

        app(VacationRequestService::class)->approve($request, null);

        Notification::assertSentTo($account, VacationRequestResolved::class, fn (VacationRequestResolved $notification) => str_contains($notification->toExpo($account)['body'], 'aprobadas'));
    }

    public function test_cancelling_notifies_the_worker_account(): void
    {
        Notification::fake();
        Queue::fake();

        $request = $this->pendingRequest();
        $account = $this->account($request);

        app(VacationRequestService::class)->cancel($request, null);

        Notification::assertSentTo($account, VacationRequestResolved::class, function (VacationRequestResolved $notification) use ($account) {
            $message = $notification->toExpo($account);

            return $message['title'] === 'Vacaciones canceladas'
                && str_contains($message['body'], 'cancelada');
        });
    }

    public function test_a_fresh_device_punch_notifies_the_worker_once(): void
    {
        Notification::fake();
        Queue::fake();
        config(['time_clock.log' => false]);
        $this->travelTo(now()->parse('2026-09-14 08:00', config('time_clock.timezone')));

        $employee = Employee::factory()->create();
        TimeClockUser::create(['pin' => '1001', 'name' => 'Ana', 'employee_id' => $employee->id]);
        $account = WorkerAccount::create([
            'employee_id' => $employee->id,
            'nip' => '482915',
            'activated_at' => now(),
        ]);

        // Una checada de hace minutos y una vieja de un reenvío del historial.
        $batch = "1001\t2026-09-14 07:58:12\t0\t15\t0\t0\n1001\t2026-09-13 18:00:00\t1\t15\t0\t0\n";
        $post = fn (string $stamp) => $this->call('POST', "/iclock/cdata?SN=NYU7253800622&table=ATTLOG&Stamp={$stamp}", [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $batch);

        $post('100')->assertOk();

        $this->assertDatabaseCount('attendance_punches', 2);
        Notification::assertSentToTimes($account, PunchRegistered::class, 1);
        Notification::assertSentTo($account, PunchRegistered::class, function (PunchRegistered $notification) use ($account) {
            $message = $notification->toExpo($account);

            return str_contains($message['body'], 'Entrada')
                && str_contains($message['body'], '07:58')
                && $message['data'] === ['url' => '/'];
        });

        // El equipo no recibió el acuse y reenvía el lote: no se vuelve a avisar.
        $post('101')->assertOk();
        Notification::assertSentToTimes($account, PunchRegistered::class, 1);
    }

    public function test_a_manual_punch_notifies_the_worker(): void
    {
        Notification::fake();
        Queue::fake();

        $employee = Employee::factory()->create();
        $account = WorkerAccount::create([
            'employee_id' => $employee->id,
            'nip' => '482915',
            'activated_at' => now(),
        ]);

        Permission::findOrCreate('checador.extraoficial', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('checador.extraoficial');
        Sanctum::actingAs($user);

        $this->postJson('/api/time-clock/punches', [
            'employee_id' => $employee->id,
            'punched_at' => now(config('time_clock.timezone'))->subHour()->format('Y-m-d H:i'),
            'punch_type' => AttendancePunch::TYPE_IN,
            'notes' => 'Olvidó checar la entrada',
        ])->assertCreated();

        Notification::assertSentTo($account, PunchRegistered::class, function (PunchRegistered $notification) use ($account) {
            $message = $notification->toExpo($account);

            return str_contains($message['body'], 'RH capturó')
                && str_contains($message['body'], 'Entrada');
        });
    }

    public function test_an_employee_without_an_account_is_not_notified(): void
    {
        Notification::fake();
        Queue::fake();

        $request = $this->pendingRequest();

        app(VacationRequestService::class)->approve($request, null);

        $this->assertSame(VacationRequest::APPROVED, $request->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_the_expo_channel_sends_to_every_device_and_drops_dead_tokens(): void
    {
        Http::fake([
            'exp.host/*' => Http::response(['data' => [
                ['status' => 'ok', 'id' => 'ticket-1'],
                ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
            ]]),
        ]);

        $request = $this->pendingRequest();
        $request->update(['status' => VacationRequest::APPROVED]);

        $account = $this->account($request);
        $alive = $this->device($account, 'ExponentPushToken[alive]', 'install-1');
        $dead = $this->device($account, 'ExponentPushToken[dead]', 'install-2');

        $account->notifyNow(new VacationRequestResolved($request));

        Http::assertSent(function (Request $httpRequest) {
            $payload = $httpRequest->data();

            return count($payload) === 2
                && $payload[0]['to'] === 'ExponentPushToken[alive]'
                && $payload[1]['to'] === 'ExponentPushToken[dead]'
                && $payload[0]['title'] === 'Vacaciones aprobadas'
                && $payload[0]['channelId'] === 'default'
                && $payload[0]['data'] === ['url' => '/vacaciones'];
        });

        $this->assertSame('ExponentPushToken[alive]', $alive->fresh()->expo_push_token);
        $this->assertNull($dead->fresh()->expo_push_token);
    }

    public function test_a_worker_without_push_tokens_sends_nothing(): void
    {
        Http::fake();

        $request = $this->pendingRequest();
        $request->update(['status' => VacationRequest::REJECTED]);

        $account = $this->account($request);
        $this->device($account, null);

        $account->notifyNow(new VacationRequestResolved($request));

        Http::assertNothingSent();
    }
}
