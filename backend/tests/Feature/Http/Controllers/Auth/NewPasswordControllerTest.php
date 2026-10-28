<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

test('reset changes the password revokes all previous access tokens and consumes the reset link', function () {
    $user = User::factory()->create();
    $oldPasswordHash = $user->password;
    $oldRemember = $user->remember_token;
    $oldAccess = $user->createToken('old-device');
    $user->createToken('second-device');
    $token = Password::createToken($user);
    Event::fake([PasswordReset::class]);
    $payload = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];

    $this->postJson('/api/reset-password', $payload)->assertOk()
        ->assertExactJson(['message' => 'Password aggiornata. Accedi con la nuova password.']);

    $user->refresh();
    expect(Hash::check('NewPassword123!', $user->password))->toBeTrue();
    expect($user->password)->not->toBe($oldPasswordHash);
    expect($user->remember_token)->not->toBe($oldRemember);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    Event::assertDispatched(PasswordReset::class, fn ($event) => $event->user->id === $user->id);
    $this->postJson('/api/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'NewPassword123!'])->assertOk();
    Auth::forgetGuards();
    $this->withToken($oldAccess->plainTextToken)->getJson('/api/user')->assertUnauthorized();
});

test('invalid expired or mismatched reset credentials return 422 without changing the account', function (string $scenario) {
    $this->freezeTime();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $hash = $user->password;
    $otherHash = $other->password;
    $access = $user->createToken('existing');
    $valid = Password::createToken($user);
    if ($scenario === 'expired') {
        $this->travel(61)->minutes();
    }
    $email = $scenario === 'other user' ? $other->email : $user->email;
    $token = $scenario === 'invalid' ? 'invalid-token' : $valid;
    Event::fake([PasswordReset::class]);

    $this->postJson('/api/reset-password', [
        'email' => $email, 'token' => $token,
        'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
    ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'Link di recupero non valido o scaduto. Richiedine uno nuovo.');

    expect($user->fresh()->password)->toBe($hash);
    expect($other->fresh()->password)->toBe($otherHash);
    $this->assertModelExists($access->accessToken);
    Event::assertNotDispatched(PasswordReset::class);
})->with(['invalid', 'expired', 'other user']);

test('reset validates confirmation length and required fields before changing the password', function (array $overrides, array $errors) {
    $user = User::factory()->create();
    $hash = $user->password;
    $token = Password::createToken($user);
    $payload = array_replace([
        'email' => $user->email, 'token' => $token,
        'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
    ], $overrides);

    $this->postJson('/api/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors($errors);

    expect($user->fresh()->password)->toBe($hash);
    expect(Password::tokenExists($user, $token))->toBeTrue();
})->with([
    'confirmation mismatch' => [['password_confirmation' => 'DifferentPassword123!'], ['password']],
    'too short' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
    'missing fields' => [['email' => '', 'token' => '', 'password' => '', 'password_confirmation' => ''], ['email', 'token', 'password']],
]);
