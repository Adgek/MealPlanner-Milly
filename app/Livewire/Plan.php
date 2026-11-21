<?php

namespace App\Livewire;

use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The plan page.
 *
 * A placeholder shell for now. MM-8 turns this into the real plan: the nights in
 * the current shopping window, who cooks, and the grocery list with checkboxes
 * that both partners see update live.
 */
#[Title('Plan')]
class Plan extends Component
{
    /**
     * Proves the Livewire round-trip works end to end, and that a stored instant
     * comes out as kitchen time. MM-8 replaces this with real state.
     *
     * The app computes in UTC, so this has to convert: it is the display half of
     * the rule that instants are stored absolute and rendered local. Pass a time
     * zone to format without reading the setting.
     */
    public function householdTime(?string $timeZone = null): string
    {
        return Carbon::now()
            ->setTimezone($timeZone ?? $this->householdTimeZone())
            ->format('D j M, g:i:s a');
    }

    /**
     * The household's time zone, as the clock's label.
     */
    public function householdTimeZone(): string
    {
        return HouseholdSettings::timeZone();
    }

    public function render(): View
    {
        return view('livewire.plan');
    }
}
