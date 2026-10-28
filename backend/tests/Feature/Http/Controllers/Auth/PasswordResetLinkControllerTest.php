<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

test('a reset link is returned only when all three development conditions are enabled', function (string $environment, bool $debug, bool $expose, bool $visible) {
    $user = User::factory()->create();
    Notification::fake();
    $this->app->instance('env', $environment);
    config(['app.debug' => $debug, 'auth.expose_reset_url' => $expose, 'app.frontend_url' => 'http://localhost:4200']);

    $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

    $response->assertOk();
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    if ($visible) {
        $url = $response->json('reset_url');
        expect($url)->toStartWith('http://localhost:4200/reset-password?');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect($query['email'])->toBe($user->email);
        expect(Password::tokenExists($user, $query['token']))->toBeTrue();
        expect(DB::table('password_reset_tokens')->value('token'))->not->toBe($query['token']);
        Notification::assertNothingSent();
    } else {
        $response->assertJsonMissingPath('reset_url');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            expect(Password::tokenExists($user, $notification->token))->toBeTrue();

            return true;
        });
    }
})->with([
    'all local switches enabled' => ['local', true, true, true],
    'explicit switch disabled' => ['local', true, false, false],
    'debug disabled' => ['local', false, true, false],
    'production even with switches enabled' => ['production', true, true, false],
]);

test('known and unknown emails receive the same generic response outside the development shortcut', function () {
    $user = User::factory()->create();
    Notification::fake();
    config(['auth.expose_reset_url' => false]);

    $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
    $unknown = $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])->assertOk();

    expect($unknown->json())->toBe($known->json());
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    $this->assertDatabaseCount('password_reset_tokens', 1);
});

test('repeated requests within sixty seconds do not replace the reset token or send another notification', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    Notification::fake();
    config(['auth.expose_reset_url' => false]);
    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
    $first = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->value('token'))->toBe($first);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('forgot password returns 422 for missing or malformed email', function (array $payload) {
    Notification::fake();

    $this->postJson('/api/forgot-password', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);

    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
})->with(['missing' => [[]], 'malformed' => [['email' => 'invalid']]]);

test('forgot password limits requests to five per minute per IP', function () {
    Notification::fake();
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])->assertOk();
    }

    $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])->assertTooManyRequests();

    Notification::assertNothingSent();
});
