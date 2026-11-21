<?php

use Illuminate\Support\Facades\DB;

/*
 * SESSION_DRIVER is `database`, so the sessions table has to match what Laravel's
 * DatabaseSessionHandler writes. phpunit.xml uses the much faster `array` driver
 * for every other test, which means nothing else here would notice a missing
 * column until a real request hit it. This test is that guard.
 */

it('writes a session row on a real request', function () {
    config(['session.driver' => 'database']);

    $this->get('/plan')->assertOk();

    expect(DB::table('sessions')->count())->toBe(1);
});

it('has every column the session handler writes', function () {
    $columns = DB::getSchemaBuilder()->getColumnListing('sessions');

    // The set Illuminate\Session\DatabaseSessionHandler fills in.
    expect($columns)->toContain('id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity');
});
