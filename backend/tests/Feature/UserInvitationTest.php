<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\SetInitialPassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

function invitationAdministrator(): User
{
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $actor->roles()->attach($role);

    return $actor;
}

function invitationPayload(User $actor): array
{
    return [
        'first_name' => 'Nuovo', 'last_name' => 'Utente', 'email' => 'invited@example.com',
        'azure_email' => 'invited@azure.example.com', 'company_email' => 'invited@company.example.com',
        'birth_date' => '1995-01-01', 'hiring_date' => '2026-01-01', 'filestorage_id' => $actor->filestorage_id,
        'role_id' => $actor->roles()->firstOrFail()->id, 'contract_start_date' => '2026-01-01', 'weekly_hours' => 40,
    ];
}

test('a passwordless account receives an invitation and can set its password once', function () {
    $actor = invitationAdministrator();
    Notification::fake();
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', invitationPayload($actor))->assertCreated()
        ->assertJsonPath('invitation.status', 'sent')->assertJsonMissingPath('invitation.reset_url')
        ->assertJsonMissingPath('data.password');
    $user = User::findOrFail($response->json('data.id'));
    expect($user->password)->not->toBeEmpty();
    $token = null;
    Notification::assertSentTo($user, SetInitialPassword::class, function ($notification) use ($user, &$token) {
        $token = $notification->token;
        expect(Password::tokenExists($user, $token))->toBeTrue();
        expect(DB::table('password_reset_tokens')->where('email', $user->email)->value('token'))->not->toBe($token);

        return true;
    });
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Password123!'])->assertUnprocessable();
    $reset = ['email' => $user->email, 'token' => $token, 'password' => 'FirstPassword123!', 'password_confirmation' => 'FirstPassword123!'];
    $this->postJson('/api/reset-password', $reset)->assertOk();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'FirstPassword123!'])->assertOk()->assertJsonStructure(['access_token']);
    $this->postJson('/api/reset-password', $reset)->assertUnprocessable();
});

test('invitation links are exposed only with all three development conditions', function (string $environment, bool $debug, bool $expose, bool $visible) {
    $actor = invitationAdministrator();
    Notification::fake();
    $this->app->instance('env', $environment);
    config(['app.debug' => $debug, 'auth.expose_reset_url' => $expose, 'app.frontend_url' => 'http://localhost:4200']);
    $response = $this->withToken($actor->createToken('test')->plainTextToken)->postJson('/api/users', invitationPayload($actor))
        ->assertCreated()->assertJsonPath('invitation.status', 'sent');
    $user = User::findOrFail($response->json('data.id'));
    Notification::assertSentTo($user, SetInitialPassword::class);
    if ($visible) {
        $url = $response->json('invitation.reset_url');
        expect($url)->toStartWith('http://localhost:4200/reset-password?');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect($query['email'])->toBe($user->email);
        expect(Password::tokenExists($user, $query['token']))->toBeTrue();
    } else {
        $response->assertJsonMissingPath('invitation.reset_url');
    }
})->with([
    ['local', true, true, true], ['local', true, false, false],
    ['local', false, true, false], ['production', true, true, false],
]);

test('creation rejects an administrator supplied password', function () {
    $actor = invitationAdministrator();
    Notification::fake();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', invitationPayload($actor) + ['password' => 'AdminChosen123!'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    Notification::assertNothingSent();
    $this->assertDatabaseCount('users', 1);
});

test('invalid contracts do not create accounts or send invitations', function () {
    $actor = invitationAdministrator();
    Notification::fake();
    $payload = invitationPayload($actor);
    $payload['weekly_hours'] = 20;
    $this->withToken($actor->createToken('test')->plainTextToken)->postJson('/api/users', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['working_days']);
    Notification::assertNothingSent();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('contracts', 0);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

test('mail failure preserves the created account and reports invitation failure without exposing details', function () {
    $actor = invitationAdministrator();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP internal details'));
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', invitationPayload($actor))->assertCreated()
        ->assertJsonPath('invitation.status', 'failed')->assertJsonMissingPath('invitation.reset_url');
    expect($response->getContent())->not->toContain('SMTP internal details');
    $user = User::findOrFail($response->json('data.id'));
    expect($user->contracts()->count())->toBe(1);
    expect($user->roles()->count())->toBe(1);
});

test('expired invitations can be replaced through forgot password', function () {
    $this->freezeTime();
    $actor = invitationAdministrator();
    Notification::fake();
    $response = $this->withToken($actor->createToken('test')->plainTextToken)->postJson('/api/users', invitationPayload($actor))->assertCreated();
    $user = User::findOrFail($response->json('data.id'));
    $token = Notification::sent($user, SetInitialPassword::class)->first()->token;
    $this->travel(61)->minutes();
    $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'FirstPassword123!', 'password_confirmation' => 'FirstPassword123!'])
        ->assertUnprocessable();
    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
    Notification::assertSentTo($user, ResetPassword::class, fn ($notification) => Password::tokenExists($user, $notification->token));
});

test('the invitation email explains the first access and uses the Angular password page', function () {
    $user = User::factory()->make();
    config(['app.frontend_url' => 'http://localhost:4200']);
    $mail = (new SetInitialPassword('sample-token'))->toMail($user);
    expect($mail->subject)->toBe('Imposta la password del tuo nuovo account');
    expect($mail->actionText)->toBe('Imposta password');
    expect($mail->actionUrl)->toStartWith('http://localhost:4200/reset-password?');
    expect(implode(' ', $mail->outroLines))->toContain('60 minuti');
});

test('the invitation can render through the actual array mail transport', function () {
    $actor = invitationAdministrator();
    config(['mail.default' => 'array']);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', invitationPayload($actor))->assertCreated()->assertJsonPath('invitation.status', 'sent');
});
