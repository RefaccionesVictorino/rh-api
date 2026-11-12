<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TimeClockUser;
use App\Models\VacationRequest;
use App\Models\WorkerAccount;
use App\Services\VacationRequestService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerNotificationTest extends TestCase
{
    private WorkerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $employee = Employee::factory()->create();
        TimeClockUser::create(['pin' => $employee->rfc, 'name' => $employee->name, 'employee_id' => $employee->id]);

        $this->account = WorkerAccount::create([
            'employee_id' => $employee->id,
            'nip' => '482915',
            'activated_at' => now(),
        ]);

        Sanctum::actingAs($this->account, ['worker']);
    }

    private function notificationFor(WorkerAccount $account, array $overrides = []): string
    {
        $notification = $account->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\Notifications\VacationRequestResolved',
            'data' => [
                'title' => 'Vacaciones aprobadas',
                'message' => 'Tus vacaciones del 07/12/2026 al 11/12/2026 fueron aprobadas.',
                'url' => '/vacaciones',
            ],
            ...$overrides,
        ]);

        return $notification->id;
    }

    public function test_the_worker_sees_their_inbox_with_the_unread_count(): void
    {
        $this->notificationFor($this->account);
        $this->notificationFor($this->account, ['read_at' => now()]);

        $response = $this->getJson('/api/worker/notifications');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.title', 'Vacaciones aprobadas')
            ->assertJsonPath('data.0.url', '/vacaciones');
    }

    public function test_the_worker_marks_a_notification_as_read(): void
    {
        $id = $this->notificationFor($this->account);

        $this->postJson("/api/worker/notifications/{$id}/read")->assertOk();

        $this->assertNotNull($this->account->notifications()->find($id)->read_at);
    }

    public function test_the_worker_marks_everything_as_read(): void
    {
        $this->notificationFor($this->account);
        $this->notificationFor($this->account);

        $this->postJson('/api/worker/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);

        $this->assertSame(0, $this->account->unreadNotifications()->count());
    }

    public function test_the_worker_cannot_touch_someone_elses_notification(): void
    {
        $otherEmployee = Employee::factory()->create();
        $other = WorkerAccount::create([
            'employee_id' => $otherEmployee->id,
            'nip' => '915482',
            'activated_at' => now(),
        ]);
        $id = $this->notificationFor($other);

        $this->postJson("/api/worker/notifications/{$id}/read")->assertNotFound();
        $this->deleteJson("/api/worker/notifications/{$id}")->assertNotFound();
        $this->assertNotNull($other->notifications()->find($id));
    }

    public function test_the_worker_deletes_a_notification(): void
    {
        $id = $this->notificationFor($this->account);
        $kept = $this->notificationFor($this->account, ['read_at' => now()]);

        $this->deleteJson("/api/worker/notifications/{$id}")
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);

        $this->getJson('/api/worker/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept);
    }

    public function test_the_worker_empties_their_inbox(): void
    {
        $this->notificationFor($this->account);
        $this->notificationFor($this->account, ['read_at' => now()]);

        $this->deleteJson('/api/worker/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);

        $this->getJson('/api/worker/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_resolving_a_request_lands_in_the_inbox(): void
    {
        Http::fake();

        $employee = $this->account->employee;
        $request = $employee->vacationRequests()->create([
            'starts_on' => '2026-12-07',
            'ends_on' => '2026-12-11',
            'requested_days' => 5,
        ]);

        app(VacationRequestService::class)->reject($request, null, 'Cobertura de la sucursal');

        $this->getJson('/api/worker/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.title', 'Solicitud de vacaciones rechazada')
            ->assertJsonPath('data.0.url', '/vacaciones');
    }
}
