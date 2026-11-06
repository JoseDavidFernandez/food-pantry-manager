<?php

use App\Enums\HouseholdRole;
use App\Models\User;

/**
 * Host header poisoning: every email that carries an absolute link (password reset, email
 * verification and household invitation) must point to APP_URL, never to the host sent in the
 * request. The links are generated while the request is handled, so the test fires each flow at
 * a forged absolute URL (http://evil.example/...) and reads the link from the message that the
 * array mail transport actually rendered, not just that a mail was queued.
 *
 * Under `php artisan test` the framework's TrustHosts middleware is inert
 * (`shouldSpecifyTrustedHosts()` is false while running tests), so the forged request reaches the
 * controller. These tests therefore exercise the second layer of the fix,
 * `URL::forceRootUrl(config('app.url'))`; TrustHosts is the edge defence that rejects the forged
 * host in production.
 */
const EVIL_HOST = 'evil.example';

/**
 * Every absolute URL found in the HTML and text body of every mail the array transport rendered.
 *
 * @return list<string>
 */
function sentMailUrls(): array
{
    $transport = app('mail.manager')->mailer()->getSymfonyTransport();
    $urls = [];

    foreach ($transport->messages() as $sent) {
        $email = $sent->getOriginalMessage();
        $body = ($email->getHtmlBody() ?? '').' '.($email->getTextBody() ?? '');
        preg_match_all('#https?://[^\s"\'<>)]+#i', $body, $matches);
        $urls = array_merge($urls, $matches[0]);
    }

    return $urls;
}

/**
 * Asserts the rendered mails carry a link to the given route path, rooted at APP_URL, and that no
 * link anywhere points to the forged host.
 */
function assertLinkUsesAppUrl(string $pathNeedle): void
{
    $urls = sentMailUrls();
    $appUrl = (string) config('app.url');

    expect($urls)->not->toBeEmpty('No se renderizó ningún correo con enlaces.');

    foreach ($urls as $url) {
        expect($url)->not->toContain(EVIL_HOST);
    }

    $relevant = array_values(array_filter($urls, fn (string $url) => str_contains($url, $pathNeedle)));

    expect($relevant)->not->toBeEmpty("No se encontró el enlace que contiene «{$pathNeedle}».");

    foreach ($relevant as $url) {
        expect(str_starts_with($url, $appUrl))->toBeTrue("El enlace {$url} no empieza por APP_URL ({$appUrl}).");
    }
}

it('la invitación a un hogar enlaza a APP_URL aunque el Host sea falso (RN-39)', function () {
    $admin = User::factory()->withHousehold()->create();

    $this->actingAs($admin)
        ->post('http://'.EVIL_HOST.'/settings/household/invitations', [
            'email' => 'invitada@example.com',
            'role' => HouseholdRole::Editor->value,
        ])
        ->assertSessionHasNoErrors();

    assertLinkUsesAppUrl('/invitations/');
});

it('el enlace de restablecer contraseña usa APP_URL aunque el Host sea falso', function () {
    $user = User::factory()->create(['email' => 'persona@example.com']);

    $this->post('http://'.EVIL_HOST.'/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors();

    assertLinkUsesAppUrl('/reset-password/');
});

it('el enlace de verificación de correo usa APP_URL aunque el Host sea falso', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post('http://'.EVIL_HOST.'/email/verification-notification');

    assertLinkUsesAppUrl('/email/verify/');
});
