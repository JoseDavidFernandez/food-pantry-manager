<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Login rate limits (FortifyServiceProvider › configureRateLimiting). Every POST /login counts:
 * - email + IP: 5 per minute;
 * - email alone: 10 per 15 minutes, whatever the IP;
 * - IP alone: 20 per minute, whatever the email.
 */

function attemptLogin(string $email, string $password, string $ip = '127.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);
}

it('blocks the sixth attempt for the same email and IP, and lets the user in after a minute', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        attemptLogin($user->email, 'wrong-password')->assertSessionHasErrors('email');
    }

    // Even the right password is refused while the limit lasts; the email case does not matter.
    attemptLogin(strtoupper($user->email), 'password')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
    $this->assertGuest();

    $this->travel(61)->seconds();

    attemptLogin($user->email, 'password')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
});

it('blocks the account after ten attempts in fifteen minutes even when the IP changes', function () {
    $user = User::factory()->create();

    // Two attempts from each of five IPs: none reaches the email + IP limit.
    foreach (range(1, 5) as $n) {
        foreach (range(1, 2) as $attempt) {
            attemptLogin($user->email, 'wrong-password', "10.0.0.{$n}")->assertSessionHasErrors('email');
        }
    }

    attemptLogin($user->email, 'password', '10.0.0.99')->assertTooManyRequests();
    $this->assertGuest();

    // Other accounts are not affected.
    $other = User::factory()->create();
    attemptLogin($other->email, 'password', '10.0.0.99')->assertRedirect(route('dashboard', absolute: false));
    auth()->logout();

    $this->travel(14)->minutes();
    attemptLogin($user->email, 'password', '10.0.0.99')->assertTooManyRequests();

    $this->travel(2)->minutes();
    attemptLogin($user->email, 'password', '10.0.0.99')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
});

it('blocks an IP after twenty attempts in a minute against different accounts', function () {
    foreach (range(1, 20) as $n) {
        attemptLogin("nobody{$n}@example.test", 'wrong-password', '10.0.1.1')->assertSessionHasErrors('email');
    }

    $user = User::factory()->create();

    attemptLogin($user->email, 'password', '10.0.1.1')->assertTooManyRequests();
    $this->assertGuest();

    // The same account from another IP is not affected.
    attemptLogin($user->email, 'password', '10.0.1.2')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
});

it('answers the 429 with the login page and a Spanish message under the email field', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        attemptLogin($user->email, 'wrong-password');
    }

    $response = attemptLogin($user->email, 'wrong-password')->assertTooManyRequests();
    $seconds = (int) $response->headers->get('Retry-After');

    expect($seconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('errors.email', "Demasiados intentos. Vuelve a intentarlo en {$seconds} segundos."));

    // The message belongs to that response only: the next visit to the login page is clean.
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('errors', []));
});

it('keeps a JSON 429 for JSON clients', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        attemptLogin($user->email, 'wrong-password');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});
