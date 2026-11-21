<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The settings page.
 *
 * A placeholder shell for now. MM-4 fills it in with the household's own values —
 * who cooks each night, how much time there is, shopping days and times, the prep
 * block, dinner time and reminder times. Those values belong here, never in code
 * (see AGENTS.md and docs/decisions.md).
 */
#[Title('Settings')]
class Settings extends Component
{
    public function render(): View
    {
        return view('livewire.settings');
    }
}
