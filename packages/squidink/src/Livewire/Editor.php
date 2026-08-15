<?php

declare(strict_types=1);

namespace Marque\SquidInk\Livewire;

use Livewire\Component;
use Marque\SquidInk\Editor\Toolbar;
use Marque\SquidInk\SquidInk;

/**
 * The editor with live preview.
 *
 * Optional. The Blade component works without Livewire — toolbar and all — and
 * this adds preview on top, because previewing means rendering, and rendering
 * has to happen server-side through the real HtmlRenderer. A JavaScript preview
 * would be a second implementation of the renderer that drifts from it, which is
 * precisely the failure mode this package exists to prevent.
 *
 * That is also why the preview is debounced rather than live per keystroke: it
 * is a round trip, and correctness is worth more here than immediacy.
 */
class Editor extends Component
{
    public string $name = 'body';

    public string $value = '';

    public ?string $parser = null;

    public int $rows = 12;

    public ?string $label = null;

    public ?string $placeholder = null;

    public bool $showPreview = false;

    public function mount(
        string $name = 'body',
        string $value = '',
        ?string $parser = null,
        int $rows = 12,
        ?string $label = null,
        ?string $placeholder = null,
    ): void {
        $this->name = $name;
        $this->value = $value;
        $this->parser = $parser;
        $this->rows = $rows;
        $this->label = $label;
        $this->placeholder = $placeholder;
    }

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }

    /**
     * The preview HTML, rendered by the same renderer that will render the saved
     * content — so what the author sees is what the post becomes.
     */
    public function getPreviewProperty(): string
    {
        if ($this->value === '') {
            return '';
        }

        return $this->squidInk()->convert($this->value, $this->parserName(), 'html');
    }

    public function getToolbarProperty(): Toolbar
    {
        return $this->squidInk()->toolbar($this->parserName());
    }

    public function render()
    {
        return view('squidink::livewire.editor');
    }

    private function parserName(): string
    {
        return $this->parser ?? $this->squidInk()->defaultParser();
    }

    private function squidInk(): SquidInk
    {
        return app(SquidInk::class);
    }
}
