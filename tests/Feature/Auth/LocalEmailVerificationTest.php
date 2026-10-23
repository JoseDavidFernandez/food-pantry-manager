<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;

test('in the local environment a newly registered user is marked as verified (DT-09)', function () {
    app()->detectEnvironment(fn () => 'local');
    $user = User::factory()->unverified()->create();

    event(new Registered($user));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('production keeps email verification', function () {
    app()->detectEnvironment(fn () => 'production');
    $user = User::factory()->unverified()->create();

    event(new Registered($user));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('outside the local environment registration still requires verification', function () {
    expect(app()->environment())->toBe('testing');

    $this->post(route('register.store'), [
        'name' => 'Ana López',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'ana@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});
