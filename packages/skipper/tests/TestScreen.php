<?php

declare(strict_types=1);

namespace Marque\Skipper\Tests;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Stands in for a screen a package would contribute.
 *
 * Renders nothing interesting on purpose: these tests are about whether the
 * request is allowed to reach it, not what it draws.
 */
class TestScreen extends Component
{
    public function render(): View
    {
        return view('skipper-test::screen');
    }
}
