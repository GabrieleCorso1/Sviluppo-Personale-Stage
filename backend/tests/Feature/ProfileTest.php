<?php

use App\Models\Role;
use App\Models\AssignmentRole;
use App\Models\User;
use App\Support\ProjectParticipantAvatar;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->token = $this->owner->createToken('profile');
    $this->withToken($this->token->plainTextToken);
});

function profilePng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
}

test('profile includes own roles assignment and default avatar without credentials or storage paths', function () {
    $this->owner->roles()->attach(Role::create(['alias' => 'User']));
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Front-end']);
    $this->owner->assignmentRoles()->attach($role, ['expertise' => 'junior']);
    $this->getJson('/api/user/profile?user_id='.$this->other->id)->assertOk()
        ->assertJsonPath('data.id', $this->owner->id)
        ->assertJsonPath('data.email', $this->owner->email)
        ->assertJsonPath('data.hiring_date', '2020-01-01')
        ->assertJsonPath('data.roles.0.alias', 'User')
        ->assertJsonPath('data.assignment_roles.0.alias', 'Sviluppatore Front-end')
        ->assertJsonPath('data.avatar_requires_auth', false)
        ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.filestorage');
});

test('password change revokes all own sessions preserves others and permits new login', function () {
    $this->owner->createToken('second');
    $otherToken = $this->other->createToken('other');
    $this->putJson('/api/user/password', [
        'current_password' => 'password', 'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!', 'user_id' => $this->other->id,
    ])->assertOk();
    expect($this->owner->tokens()->count())->toBe(0);
    expect(Hash::check('NewPassword123!', $this->owner->fresh()->password))->toBeTrue();
    expect(Hash::check('password', $this->other->fresh()->password))->toBeTrue();
    $this->assertModelExists($otherToken->accessToken);
    Auth::forgetGuards();
    $this->getJson('/api/user/profile')->assertUnauthorized();
    $this->postJson('/api/login', ['email' => $this->owner->email, 'password' => 'NewPassword123!'])->assertOk();
});

test('invalid password requests preserve credentials and session', function (array $body, string $field) {
    $hash = $this->owner->password;
    $this->putJson('/api/user/password', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->owner->fresh()->password)->toBe($hash);
    $this->assertModelExists($this->token->accessToken);
})->with([
    [['current_password'=>'wrong', 'password'=>'NewPassword123!', 'password_confirmation'=>'NewPassword123!'], 'current_password'],
    [['current_password'=>'password', 'password'=>'short', 'password_confirmation'=>'short'], 'password'],
    [['current_password'=>'password', 'password'=>'NewPassword123!', 'password_confirmation'=>'mismatch'], 'password'],
    [['current_password'=>'password', 'password'=>'password', 'password_confirmation'=>'password'], 'password'],
    [[], 'current_password'],
]);

test('avatar upload replace and removal update the shared project image and preserve other users', function () {
    $originalOther = $this->other->filestorage_id;
    $this->post('/api/user/avatar', ['avatar'=>profilePng(), 'user_id'=>$this->other->id], ['Accept'=>'application/json'])
        ->assertOk()->assertJsonPath('data.avatar_requires_auth', true);
    $first = ProjectParticipantAvatar::privatePath($this->owner->fresh());
    expect($first)->not->toBeNull();
    Storage::disk('local')->assertExists($first);
    $this->get('/api/user/avatar')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->post('/api/user/avatar', ['avatar'=>profilePng()], ['Accept'=>'application/json'])->assertOk();
    $second = ProjectParticipantAvatar::privatePath($this->owner->fresh());
    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);
    $this->deleteJson('/api/user/avatar')->assertOk()->assertJsonPath('data.avatar_requires_auth', false);
    Storage::disk('local')->assertMissing($second);
    expect($this->other->fresh()->filestorage_id)->toBe($originalOther);
    $this->get('/api/user/avatar')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->deleteJson('/api/user/avatar')->assertOk();
});

test('avatar rejects missing fake svg and oversized files', function (string $kind) {
    $file = match($kind) {
        'fake' => UploadedFile::fake()->createWithContent('fake.png', 'not an image'),
        'svg' => UploadedFile::fake()->createWithContent('test.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        'large' => profilePng()->size(2049),
        default => null,
    };
    $old = $this->owner->filestorage_id;
    $this->post('/api/user/avatar', $file ? ['avatar'=>$file] : [], ['Accept'=>'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('avatar');
    expect($this->owner->fresh()->filestorage_id)->toBe($old);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['missing','fake','svg','large']);

test('removing a shared avatar does not remove the other users file', function () {
    $this->post('/api/user/avatar', ['avatar'=>profilePng()], ['Accept'=>'application/json'])->assertOk();
    $this->other->update(['filestorage_id'=>$this->owner->fresh()->filestorage_id]);
    $path = ProjectParticipantAvatar::privatePath($this->owner->fresh());
    $this->deleteJson('/api/user/avatar')->assertOk();
    Storage::disk('local')->assertExists($path);
    expect(ProjectParticipantAvatar::privatePath($this->other->fresh()))->toBe($path);
});

test('all profile endpoints require authentication', function (string $method, string $url) {
    Auth::forgetGuards();
    $this->withHeader('Authorization', '')->json($method, $url)->assertUnauthorized();
})->with([
    ['GET','/api/user/profile'], ['GET','/api/user/avatar'], ['POST','/api/user/avatar'],
    ['DELETE','/api/user/avatar'], ['PUT','/api/user/password'],
]);
