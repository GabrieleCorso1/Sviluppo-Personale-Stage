<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

test('login issues a usable hashed token with the selected expiry', function (bool $remember, int $hours) {
    $this->freezeTime();
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'email' => $user->email, 'password' => 'password', 'remember' => $remember,
    ]);

    $response->assertOk()->assertJsonPath('token_type', 'Bearer');
    $plain = $response->json('access_token');
    $token = PersonalAccessToken::findToken($plain);
    expect($token)->not->toBeNull();
    expect($token->tokenable_id)->toBe($user->id);
    expect($token->expires_at->timestamp)->toBe(now()->addHours($hours)->timestamp);
    expect($response->json('expires_at'))->toBe($token->expires_at->toIso8601String());
    expect($token->token)->not->toBe($plain);
    $this->withToken($plain)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
})->with(['normal access' => [false, 8], 'remember me' => [true, 720]]);

test('login rejects unknown users and incorrect passwords with the same 422 error', function (bool $known) {
    $email = $known ? User::factory()->create()->email : 'missing@example.com';

    $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonPath('errors.email.0', 'Email o password non corrette.');

    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([true, false]);

test('login returns 422 for invalid input without issuing tokens', function (array $payload, array $fields) {
    $this->postJson('/api/login', $payload)->assertUnprocessable()->assertJsonValidationErrors($fields);

    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'missing credentials' => [[], ['email', 'password']],
    'invalid email' => [['email' => 'invalid', 'password' => 'password'], ['email']],
    'invalid remember' => [['email' => 'user@example.com', 'password' => 'password', 'remember' => 'invalid'], ['remember']],
]);

test('login returns 429 after five failures and accepts credentials after the cooldown', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->travel(61)->seconds();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
});

test('soft deleted users cannot obtain login tokens', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('protected routes return 401 without a valid bearer token', function (string $method, string $path) {
    $this->json($method, $path)->assertUnauthorized();
})->with([['GET', '/api/user'], ['POST', '/api/logout']]);

test('an expired bearer token returns 401', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $token = $user->createToken('test', ['*'], now()->subSecond());

    $this->withToken($token->plainTextToken)->getJson('/api/user')->assertUnauthorized();
});

test('logout revokes only the current token and denies its reuse', function () {
    $user = User::factory()->create();
    $current = $user->createToken('current');
    $other = $user->createToken('other-device');

    $this->withToken($current->plainTextToken)->postJson('/api/logout')
        ->assertOk()->assertExactJson(['message' => 'Disconnessione effettuata.']);

    $this->assertModelMissing($current->accessToken);
    $this->assertModelExists($other->accessToken);
    Auth::forgetGuards();
    $this->withToken($current->plainTextToken)->getJson('/api/user')->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($other->plainTextToken)->getJson('/api/user')->assertOk();
});
