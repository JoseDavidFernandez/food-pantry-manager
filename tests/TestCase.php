<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const TESTING_DATABASE = 'food_pantry_manager_test';

    /**
     * Abort before any trait (e.g. RefreshDatabase) touches a database other than the testing one.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($database !== self::TESTING_DATABASE) {
            throw new RuntimeException(sprintf(
                'Tests must run against [%s], but connection [%s] points to [%s]. Run "php artisan config:clear" and check phpunit.xml.',
                self::TESTING_DATABASE,
                $connection,
                $database,
            ));
        }

        return parent::setUpTraits();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
