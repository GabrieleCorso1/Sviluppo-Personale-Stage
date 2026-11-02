<?php
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

uses(LazilyRefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $this->actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $this->actor->roles()->attach($role);
    $this->actorToken = $this->actor->createToken('administrator');
    $this->withToken($this->actorToken->plainTextToken);
});
afterEach(function () { $this->travelBack(); });
dataset('disable user paths', [
    'disable' => ['POST', '/disable'],
    'legacy delete' => ['DELETE', ''],
]);
test('both disable routes reject invalid resignation dates without changing user or tokens', function (string $method, string $suffix, array $payload) {
    $target = User::factory()->create(['hiring_date' => '2026-01-10']);
    $firstToken = $target->createToken('first-device');
    $secondToken = $target->createToken('second-device');
    $before = $target->fresh()->getAttributes();
    $this->json($method, '/api/users/'.$target->id.$suffix, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('resignation_date');
    expect($target->fresh()->getAttributes())->toBe($before);
    $this->assertModelExists($firstToken->accessToken);
    $this->assertModelExists($secondToken->accessToken);
})->with('disable user paths')->with([
    'missing' => [[]],
    'null' => [['resignation_date' => null]],
    'future' => [['resignation_date' => '2026-11-03']],
    'before hiring' => [['resignation_date' => '2026-01-09']],
    'invalid' => [['resignation_date' => 'not-a-date']],
    'date with time' => [['resignation_date' => '2026-11-02T10:00:00Z']],
]);
test('both disable routes preserve data revoke all tokens and restore without reviving old access', function (string $method, string $suffix, string $date) {
    $target = User::factory()->create(['hiring_date' => '2026-01-10']);
    $oldToken = $target->createToken('first-device');
    $target->createToken('second-device');
    $originalEmail = $target->email;
    $originalPassword = $target->password;
    $contract = $target->contracts()->create(['contract_start_date' => '2026-01-10', 'weekly_hours' => 40]);
    $this->json($method, '/api/users/'.$target->id.$suffix, ['resignation_date' => $date])->assertOk();
    $this->assertSoftDeleted($target);
    expect($target->fresh()->resignation_date->toDateString())->toBe($date);
    expect($target->tokens()->count())->toBe(0);
    expect($target->fresh()->email)->toBe($originalEmail);
    expect($target->fresh()->password)->toBe($originalPassword);
    $this->assertModelExists($contract);
    $this->assertModelExists($this->actorToken->accessToken);
    $this->postJson('/api/users/'.$target->id.'/restore')->assertOk();
    expect($target->fresh()->trashed())->toBeFalse();
    expect($target->fresh()->resignation_date)->toBeNull();
    expect($target->fresh()->password)->toBe($originalPassword);
    Auth::forgetGuards();
    $this->withToken($oldToken->plainTextToken)->getJson('/api/user')->assertUnauthorized();
})->with('disable user paths')->with([
    'today' => ['2026-11-02'],
    'hiring day' => ['2026-01-10'],
]);
test('both disable routes forbid self disabling before validating the date', function (string $method, string $suffix) {
    $before = $this->actor->fresh()->getAttributes();
    $this->json($method, '/api/users/'.$this->actor->id.$suffix, [])->assertForbidden();
    expect($this->actor->fresh()->getAttributes())->toBe($before);
    $this->assertModelExists($this->actorToken->accessToken);
})->with('disable user paths');
test('disable uses the current Rome date even if UTC is on the previous day', function () {
    $this->travelTo(Carbon::parse('2026-11-01 23:30:00', 'UTC'));
    $target = User::factory()->create(['hiring_date' => '2026-01-10']);
    $this->postJson('/api/users/'.$target->id.'/disable', ['resignation_date' => '2026-11-02'])->assertOk();
    expect($target->fresh()->resignation_date->toDateString())->toBe('2026-11-02');
});

test('generic editing rejects resignation date without changing data or sessions', function ($date) {
    $target = User::factory()->create();
    $token = $target->createToken('preserved');
    $before = $target->fresh()->getAttributes();
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Rollback', 'resignation_date' => $date])
        ->assertUnprocessable()->assertJsonValidationErrors('resignation_date');
    expect($target->fresh()->getAttributes())->toBe($before);
    $this->assertModelExists($token->accessToken);
})->with([['2026-11-02'], ['2099-01-01'], [null]]);