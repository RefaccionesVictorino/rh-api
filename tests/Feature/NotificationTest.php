<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\VacationRequest;
use App\Notifications\VacationRequestSubmitted;
use Carbon\CarbonImmutable;
use Database\Seeders\VacationEntitlementSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(VacationEntitlementSeeder::class);
        CarbonImmutable::setTestNow('2026-09-16');
        config(['vacations.start_date' => null]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function userWith(string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        return User::factory()->create()->givePermissionTo($permissions);
    }

    private function employee(): Employee
    {
        $employee = Employee::factory()->create([
            'hire_date' => now()->subYears(3)->toDateString(),
        ]);

        $shift = Shift::create(['name' => 'Oficina '.$employee->id, 'tolerance_minutes' => 10]);
        $shift->syncDays(collect(range(1, 5))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->all());

        $employee->shiftAssignments()->create([
            'shift_id' => $shift->id,
            'starts_on' => $employee->hire_date,
        ]);

        return $employee;
    }

    private function vacationRequest(): VacationRequest
    {
        return $this->employee()->vacationRequests()->create([
            'starts_on' => '2026-10-19',
            'ends_on' => '2026-10-23',
            'requested_days' => 5,
        ]);
    }

    public function test_a_new_request_notifies_approvers_but_not_whoever_captured_it(): void
    {
        Notification::fake();

        $requester = $this->userWith('vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.autorizar');
        $approver = $this->userWith('vacaciones.autorizar');
        $approverByRole = User::factory()->create()
            ->assignRole(Role::findOrCreate('rh', 'web')->givePermissionTo('vacaciones.autorizar'));
        $bystander = $this->userWith('vacaciones.ver');

        Sanctum::actingAs($requester);
        $this->postJson("/api/employees/{$this->employee()->id}/vacation-requests", [
            'starts_on' => '2026-10-19', 'ends_on' => '2026-10-23',
        ])->assertCreated();

        Notification::assertSentTo([$approver, $approverByRole], VacationRequestSubmitted::class);
        Notification::assertNotSentTo([$requester, $bystander], VacationRequestSubmitted::class);
    }

    public function test_the_notification_goes_to_the_inbox_the_socket_and_email(): void
    {
        $approver = $this->userWith('vacaciones.autorizar');
        $notification = new VacationRequestSubmitted($this->vacationRequest());

        $this->assertSame(['database', 'broadcast', 'mail'], $notification->via($approver));
        $this->assertSame('/vacaciones/solicitudes?estatus=pending', $notification->toArray($approver)['url']);
        $this->assertStringContainsString('5 día(s): del 19/10/2026 al 23/10/2026', $notification->toArray($approver)['message']);
    }

    public function test_a_request_is_saved_even_without_anyone_to_notify(): void
    {
        Sanctum::actingAs($this->userWith('vacaciones.ver', 'vacaciones.solicitar'));
        $this->postJson("/api/employees/{$this->employee()->id}/vacation-requests", [
            'starts_on' => '2026-10-19', 'ends_on' => '2026-10-23',
        ])->assertCreated();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_inbox_lists_only_own_notifications_with_the_unread_count(): void
    {
        $approver = $this->userWith('vacaciones.autorizar');
        $other = $this->userWith('vacaciones.autorizar');
        $approver->notify(new VacationRequestSubmitted($this->vacationRequest()));
        $approver->notify(new VacationRequestSubmitted($this->vacationRequest()));
        $other->notify(new VacationRequestSubmitted($this->vacationRequest()));

        Sanctum::actingAs($approver);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Nueva solicitud de vacaciones')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('meta.unread_count', 2);
    }

    public function test_notifications_are_marked_as_read_one_by_one_or_all_at_once(): void
    {
        $approver = $this->userWith('vacaciones.autorizar');
        $approver->notify(new VacationRequestSubmitted($this->vacationRequest()));
        $approver->notify(new VacationRequestSubmitted($this->vacationRequest()));
        $first = $approver->notifications()->first();

        Sanctum::actingAs($approver);

        $this->postJson("/api/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);
        $this->assertSame(1, $approver->unreadNotifications()->count());

        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $approver->unreadNotifications()->count());
    }

    public function test_nobody_can_mark_someone_elses_notification(): void
    {
        $owner = $this->userWith('vacaciones.autorizar');
        $owner->notify(new VacationRequestSubmitted($this->vacationRequest()));

        Sanctum::actingAs($this->userWith('vacaciones.autorizar'));

        $this->postJson("/api/notifications/{$owner->notifications()->first()->id}/read")
            ->assertNotFound();
        $this->assertSame(1, $owner->unreadNotifications()->count());
    }

    public function test_a_user_can_only_join_their_own_private_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        // Con el driver null todo canal se autoriza; el de Reverb sí evalúa
        // routes/channels.php. Las llaves son de prueba: no hay conexión.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // Los canales se registraron al arrancar en el driver null.
        require base_path('routes/channels.php');

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$user->id}",
        ])->assertOk();

        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$other->id}",
        ])->assertForbidden();
    }

    public function test_guests_cannot_authorize_channels(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.1',
        ])->assertUnauthorized();
    }
}
