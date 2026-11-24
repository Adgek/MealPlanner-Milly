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

    // One link per thing the household looks after (MM-15 added three).
    foreach (['plan', 'recipes', 'kitchen', 'kid-foods', 'settings'] as $page) {
        $response->assertSee('href="'.route($page).'"', escape: false);
    }
})->with(['/plan', '/settings', '/recipes', '/kitchen', '/kid-foods']);

it('keeps the parent page marked while you are in one of its editors', function (string $path, string $parent) {
    // The editors are reached from their list page rather than the nav, so the
    // nav has to stay honest about where you are.
    $this->get($path)
        ->assertOk()
        ->assertSeeInOrder(['href="'.route($parent).'"', 'aria-current="page"'], escape: false);
})->with([
    ['/recipes/new', 'recipes'],
    ['/recipes/bulk-ingredients', 'recipes'],
    ['/kitchen/projects/new', 'kitchen'],
]);

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
})->with(['/plan', '/settings', '/recipes', '/kitchen', '/kid-foods']);

it('shows every edit screen (MM-15)', function (string $path, string $heading) {
    $this->get($path)->assertOk()->assertSee($heading);
})->with([
    ['/recipes', 'The vault the picker draws from'],
    ['/recipes/new', 'Add a recipe'],
    ['/recipes/bulk-ingredients', 'The shared packs worth buying whole'],
    ['/kitchen', 'What you make ahead'],
    ['/kitchen/projects/new', 'Add a kitchen project'],
    ['/kid-foods', 'The guaranteed-safe backup items to keep stocked'],
]);

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
        ->assertSee('Save settings');
});
