<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test suites
|--------------------------------------------------------------------------
|
| Feature tests boot the app and run against the real MySQL test database
| (see phpunit.xml). RefreshDatabase migrates it before each test, so the
| suite also proves the migrations still apply cleanly.
|
| Unit tests get neither the framework nor a database. Planning logic
| (MM-6, MM-7) belongs there: see AGENTS.md.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Keeps `test` independent of `build`; the asset manifest is the build's job.
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');
