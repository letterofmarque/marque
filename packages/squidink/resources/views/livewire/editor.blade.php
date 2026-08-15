@php
    $toolbar = $this->toolbar;
    $editorId = 'squidink-'.$this->name;

    // No component from the id package is referenced here, for the same reason as
    // the Blade editor: Blade resolves components at compile time, so guarding one
    // with class_exists() does not stop the view exploding where marque/id is
    // absent.

    $controlClasses = 'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900
                       shadow-xs placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none
                       focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-600 dark:bg-zinc-800
                       dark:text-zinc-100 dark:placeholder:text-zinc-500';

    $controlClasses = trim(preg_replace('/\s+/', ' ', $controlClasses));
@endphp

<div
    class="flex flex-col gap-2"
    data-squidink-editor
    data-parser="{{ $this->parser ?? app(\Marque\SquidInk\SquidInk::class)->defaultParser() }}"
>
    @if ($this->label)
        <label for="{{ $editorId }}" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {{ $this->label }}
        </label>
    @endif

    <div class="flex flex-wrap items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-1
                dark:border-zinc-700 dark:bg-zinc-800/50">
        @unless ($toolbar->isEmpty())
            {{-- Hidden until the JS that makes these work has run. --}}
            <div class="flex flex-wrap gap-1" data-squidink-toolbar role="toolbar"
                 aria-label="{{ __('Formatting') }}" hidden>
                @foreach ($toolbar->insertions as $insertion)
                    <button
                        type="button"
                        data-squidink-action="{{ $insertion->action }}"
                        data-prefix="{{ $insertion->prefix }}"
                        data-suffix="{{ $insertion->suffix }}"
                        data-placeholder="{{ $insertion->placeholder }}"
                        @if ($insertion->block) data-block @endif
                        title="{{ ucfirst($insertion->action) }}"
                        aria-label="{{ ucfirst($insertion->action) }}"
                        class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-transparent
                               px-2 text-xs font-medium text-zinc-700 transition hover:bg-white hover:shadow-xs
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500
                               dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >{{ $insertion->label }}</button>
                @endforeach
            </div>
        @endunless

        <button
            type="button"
            wire:click="togglePreview"
            aria-pressed="{{ $this->showPreview ? 'true' : 'false' }}"
            class="ml-auto inline-flex h-8 items-center justify-center rounded-md border border-transparent px-2
                   text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500
                   {{ $this->showPreview
                        ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                        : 'text-zinc-700 hover:bg-white hover:shadow-xs dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
        >{{ __('Preview') }}</button>
    </div>

    <textarea
        id="{{ $editorId }}"
        rows="{{ $this->rows }}"
        @if ($this->placeholder) placeholder="{{ $this->placeholder }}" @endif
        data-squidink-input
        wire:model.live.debounce.400ms="value"
        class="{{ $controlClasses }}"
    ></textarea>

    @if ($this->showPreview)
        <div>
            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ __('Preview') }}
            </div>

            {{-- Rendered by HtmlRenderer, which only emits nodes the schema
                 permitted, so this is safe to print unescaped. That guarantee is
                 the schema's, not this template's. --}}
            <div class="prose prose-sm max-w-none rounded-lg border border-zinc-200 bg-white p-3
                        dark:prose-invert dark:border-zinc-700 dark:bg-zinc-800">
                {!! $this->preview !!}
            </div>
        </div>
    @endif
</div>
