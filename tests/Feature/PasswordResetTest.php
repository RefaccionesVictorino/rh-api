<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'nuevaClave123';

    private function resetPayload(User $user, array $overrides = []): array
    {
        return [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            ...$overrides,
        ];
    }

    public function test_sends_a_reset_link_pointing_to_the_frontend(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/forgot-password', ['email' => ' ANA@example.com '])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url').'/restablecer-contrasena?')
                && str_contains($url, 'token='.$notification->token);
        });
    }

    public function test_answers_the_same_for_unknown_emails(): void
    {
        Notification::fake();
        $known = User::factory()->create(['email' => 'ana@example.com']);

        $knownResponse = $this->postJson('/api/forgot-password', ['email' => $known->email])->assertOk();
        $unknownResponse = $this->postJson('/api/forgot-password', ['email' => 'nadie@example.com'])->assertOk();

        $this->assertSame($knownResponse->json('message'), $unknownResponse->json('message'));
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_resets_the_password_and_revokes_existing_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('victorino-rh-web');

        $this->postJson('/api/reset-password', $this->resetPayload($user))->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    public function test_a_token_can_only_be_used_once(): void
    {
        $user = User::factory()->create();
        $payload = $this->resetPayload($user);

        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->postJson('/api/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/reset-password', $this->resetPayload($user, ['token' => 'inventado']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_requires_a_matching_confirmation(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/reset-password', $this->resetPayload($user, ['password_confirmation' => 'otraClave123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}
