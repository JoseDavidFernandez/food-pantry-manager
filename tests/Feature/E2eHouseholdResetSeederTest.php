<?php

use App\Models\User;
use Database\Seeders\E2eHouseholdResetSeeder;

test('the E2E reset never runs against the test database', function () {
    putenv('E2E_USER_EMAIL=e2e@food-pantry.test');

    try {
        $this->seed(E2eHouseholdResetSeeder::class);
    } finally {
        putenv('E2E_USER_EMAIL');
    }
})->throws(RuntimeException::class, 'E2eHouseholdResetSeeder solo se ejecuta en local contra food_pantry_manager.');

test('the E2E reset refuses even in the local environment when the database is not the development one', function () {
    User::factory()->withHousehold()->create(['email' => 'e2e@food-pantry.test']);
    app()->detectEnvironment(fn () => 'local');
    putenv('E2E_USER_EMAIL=e2e@food-pantry.test');

    try {
        $this->seed(E2eHouseholdResetSeeder::class);
    } finally {
        putenv('E2E_USER_EMAIL');
        app()->detectEnvironment(fn () => 'testing');
    }
})->throws(RuntimeException::class);
