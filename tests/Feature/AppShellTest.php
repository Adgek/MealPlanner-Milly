<?php

use App\Livewire\Plan;
use App\Livewire\Settings;
use Livewire\Livewire;

it('sends the bare root at the plan page', function () {
    $this->get('/')->assertRedirect('/plan');
});

it('shows the plan page', function () {
    $this->get('/plan')
        ->assertOk()
        ->assertSee('Plan')
        ->assertSee('shopping window');
});

it('shows the settings page', function () {
    $this->get('/settings')
        ->assertOk()
        ->assertSee('Settings')
        ->assertSee('Every household value lives here');
});

it('offers the same navigation on every page', function (string $path) {
    $response = $this->get($path)->assertOk();

    $response->assertSee('href="'.route('plan').'"', escape: false);
    $response->assertSee('href="'.route('settings').'"', escape: false);
})->with(['/plan', '/settings']);

it('marks the page you are on in the navigation', function () {
    // The <a> for the current route carries aria-current; the other one does not.
    $this->get('/settings')
        ->assertOk()
        ->assertSeeInOrder(['/settings', 'aria-current="page"'], escape: false);
});

it('renders both pages inside the shared layout', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('MealPlanner Milly')
        ->assertSee('No sign-in by design');
})->with(['/plan', '/settings']);

it('has no sign-in to reach', function () {
    // MM-1 chose no login, so these must not exist.
    $this->get('/login')->assertNotFound();
    $this->get('/register')->assertNotFound();
});

it('answers the health check compose uses', function () {
    $this->get('/up')->assertOk();
});

it('mounts the plan page as a livewire component', function () {
    Livewire::test(Plan::class)
        ->assertOk()
        ->assertSee('Kitchen clock');
});

it('re-renders the plan page without a page reload', function () {
    // The wire:poll path MM-8's grocery checkboxes and MM-14's dashboard rely on.
    Livewire::test(Plan::class)
        ->assertOk()
        ->refresh()
        ->assertOk()
        ->assertSee('Kitchen clock');
});

it('mounts the settings page as a livewire component', function () {
    Livewire::test(Settings::class)
        ->assertOk()
        ->assertSee('No settings to change yet');
});
