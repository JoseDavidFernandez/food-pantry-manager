<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * CA-01: without a session, every page except the authentication ones sends to the login page.
 */
test('CA-01: guests are sent to the login page from every protected page', function () {
    $pages = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => in_array('GET', $route->methods(), true)
            && in_array('auth', $route->gatherMiddleware(), true))
        ->flatMap(function (Route $route) {
            // Fill route parameters with plausible values; guests must be stopped before using them.
            $uri = $route->uri();
            $filled = preg_replace(['/\{week\??\}/', '/\{token\}/', '/\{[a-z_]+\??\}/'], ['2026-10-19', str_repeat('a', 64), '1'], $uri);
            // Optional trailing parameters are also visited without a value.
            $paths = [$filled];

            if (preg_match('/\{[a-z_]+\?\}$/', $uri) === 1) {
                $paths[] = preg_replace('/\/?\{[a-z_]+\?\}$/', '', $uri);
            }

            return array_map(fn (?string $path) => '/'.ltrim((string) $path, '/'), $paths);
        })
        ->unique()
        ->values();

    expect($pages)->toContain('/dashboard', '/ingredients', '/inventory', '/recipes', '/menu', '/shopping-list', '/shopping-lists', '/shopping-lists/1/reception', '/settings/profile', '/settings/household', '/household/setup', '/invitations/'.str_repeat('a', 64));

    foreach ($pages as $page) {
        expect($this->get($page)->headers->get('Location'))->toBe(route('login'), "Sin sesión, {$page} no redirige al login");
    }
});

test('CA-01: the authentication pages and the welcome page are public', function () {
    foreach (['/', '/login', '/register', '/forgot-password'] as $page) {
        $this->get($page)->assertOk();
    }
});
