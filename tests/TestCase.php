<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests run against real MySQL, and RefreshDatabase drops every table it finds.
     * Refuse to start unless we are pointed at the companion test database, so a
     * misconfigured phpunit.xml or a stray DB_DATABASE can never wipe the
     * household's own data.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against the database [{$database}]: its name must end "
                .'in "_test". Check the DB_CONNECTION value in phpunit.xml.'
            );
        }
    }
}
