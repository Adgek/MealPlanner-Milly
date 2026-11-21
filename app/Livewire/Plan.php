<?php

namespace App\Livewire;

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
     * Proves the Livewire round-trip works end to end, and that the container
     * clock is on the household time zone. MM-8 replaces this with real state.
     */
    public function householdTime(): string
    {
        return Carbon::now()->format('D j M, g:i:s a');
    }

    public function render(): View
    {
        return view('livewire.plan');
    }
}
