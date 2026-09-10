<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('admin can login through the API and logout from the session', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.id', $admin->id)
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');

    $this->assertAuthenticatedAs($admin);
    $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $admin->id);
    $this->postJson('/api/logout')->assertNoContent();
    // Forget the guard cached by the previous request, like a new HTTP request.
    app('auth')->forgetGuards();
    $this->assertGuest('web');
    $this->getJson('/api/user')->assertUnauthorized();
});

test('non admins cannot login on either endpoint even with the correct password', function (string $endpoint) {
    $user = User::factory()->create(['role' => 'user']);

    $this->postJson($endpoint, ['email' => $user->email, 'password' => 'password', 'role' => 'admin'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertGuest();
})->with(['/api/login', '/login']);

test('API login rejects invalid credentials', function (bool $existing) {
    $email = $existing ? User::factory()->create(['role' => 'admin'])->email : 'missing@example.com';
    $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertGuest();
})->with([true, false]);

test('API login validates required fields', function () {
    $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    $this->assertGuest();
});

test('API and legacy login share the login rate limit', function () {
    config(['traffic.login_per_minute' => 1]);
    $credentials = ['email' => 'missing@example.com', 'password' => 'wrong-password'];
    $this->postJson('/api/login', $credentials)->assertUnprocessable();
    $this->postJson('/login', $credentials)->assertStatus(429)->assertHeader('Retry-After');
});

test('API logout requires authentication', function () {
    $this->postJson('/api/logout')->assertUnauthorized();
});
