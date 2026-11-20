<?php

use Illuminate\Support\Facades\Route;

/*
 * The production Content-Security-Policy (DEPLOY.md › Cabeceras de seguridad) allows no inline
 * scripts or styles: script-src 'self' and style-src 'self', without hashes. These tests keep the
 * HTML the server writes compatible with it.
 */

function inlineCode(string $html): array
{
    // Executable inline scripts (Inertia's page data is application/json and does not run), <style>
    // elements and style="" attributes.
    preg_match_all('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>#i', $html, $scripts);
    preg_match_all('#<style\b|\sstyle\s*=#i', $html, $styles);

    return [...$scripts[0], ...$styles[0]];
}

it('serves the application page without inline scripts or styles', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(inlineCode($html))->toBe([])
        ->and($html)->toContain('<script src="/theme-init.js"></script>');
});

it('passes only a known appearance to the page', function (?string $cookie, string $expected) {
    $request = $cookie === null ? $this : $this->withUnencryptedCookie('appearance', $cookie);

    $html = $request->get(route('home'))->getContent();

    expect($html)->toContain("data-appearance=\"{$expected}\"");
})->with([
    'none' => [null, 'system'],
    'light' => ['light', 'light'],
    'dark' => ['dark', 'dark'],
    'system' => ['system', 'system'],
    'unknown' => ["x');alert(1)//", 'system'],
]);

it('serves error pages in Spanish with the static stylesheet and no inline code', function () {
    $html = $this->get('/esta-pagina-no-existe')->assertNotFound()->getContent();

    expect($html)->toContain('Página no encontrada')
        ->toContain('<link rel="stylesheet" href="/errors.css">')
        ->and(inlineCode($html))->toBe([]);
});

it('renders the 500 and maintenance (503) pages without inline code', function (int $status, string $message) {
    config(['app.debug' => false]);
    Route::get('/_csp-test-error', fn () => $status === 500 ? throw new RuntimeException('boom') : abort($status));

    $html = $this->get('/_csp-test-error')->assertStatus($status)->getContent();

    expect($html)->toContain($message)
        ->toContain('<link rel="stylesheet" href="/errors.css">')
        ->not->toContain('boom')
        ->and(inlineCode($html))->toBe([]);
})->with([
    '500' => [500, 'Error del servidor'],
    '503' => [503, 'Servicio no disponible'],
]);

it('keeps the files the policy relies on in public/', function () {
    expect(public_path('theme-init.js'))->toBeFile()
        ->and(public_path('errors.css'))->toBeFile();
});
