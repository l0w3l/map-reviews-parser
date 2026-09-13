<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified user with a hashed password', function () {
    $this->artisan('app:create-user')
        ->expectsQuestion('Имя', 'Test User')
        ->expectsQuestion('Email', ' USER@example.com ')
        ->expectsQuestion('Пароль (минимум 12 символов)', 'long-test-password')
        ->expectsQuestion('Повторите пароль', 'long-test-password')
        ->assertSuccessful();

    $user = User::where('email', 'user@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('long-test-password', $user->password))->toBeTrue();
});

it('does not overwrite an existing user', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);
    $oldPassword = $user->password;
    $this->artisan('app:create-user')
        ->expectsQuestion('Имя', 'Test User')
        ->expectsQuestion('Email', 'user@example.com')
        ->expectsQuestion('Пароль (минимум 12 символов)', 'long-test-password')
        ->expectsQuestion('Повторите пароль', 'long-test-password')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($oldPassword);
    $this->assertDatabaseCount('users', 1);
});

it('rejects invalid credentials', function (string $email, string $password, string $confirmation) {
    $this->artisan('app:create-user')
        ->expectsQuestion('Имя', 'Test User')
        ->expectsQuestion('Email', $email)
        ->expectsQuestion('Пароль (минимум 12 символов)', $password)
        ->expectsQuestion('Повторите пароль', $confirmation)
        ->assertFailed();
    $this->assertDatabaseCount('users', 0);
})->with([
    ['invalid', 'long-test-password', 'long-test-password'],
    ['user@example.com', 'short', 'short'],
    ['user@example.com', 'long-test-password', 'another-password'],
]);
