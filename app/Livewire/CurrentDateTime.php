<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class CurrentDateTime extends Component
{
    public function render(): View
    {
        return view('livewire.current-date-time');
    }
}
