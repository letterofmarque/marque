@props([
    'name' => 'body',
    'value' => '',
    'parser' => null,
    'rows' => 12,
    'label' => null,
    'placeholder' => null,
    'id' => null,
])

@php
    use Marque\SquidInk\SquidInk;

    $squidInk = app(SquidInk::class);

    $parser ??= $squidInk->defaultParser();
    $toolbar = $squidInk->toolbar($parser);

    $editorId = $id ?? 'squidink-'.$name;

    // This template deliberately references no component from the id package.
    // Blade resolves components at COMPILE time, so a `@if (class_exists(...))`
    // guard around one does not help — the compiler tries to locate it either way
    // and the view explodes wherever marque/ise is not installed. A text pipeline
    // must not require a UI package to render, so this template owns its own
    // markup and matches id's classes by hand.
    //
    // A host that wants id's components can publish these views
    // (`--tag=squidink-views`) and swap them in; that is the seam for it.

    $controlClasses = 'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900
                       shadow-xs placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none
                       focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-600 dark:bg-zinc-800
                       dark:text-zinc-100 dark:placeholder:text-zinc-500';

    $controlClasses = trim(preg_replace('/\s+/', ' ', $controlClasses));
@endphp

<div
    {{ $attributes->class('flex flex-col gap-2') }}
    data-squidink-editor
    data-parser="{{ $parser }}"
    data-target="{{ $editorId }}"
>
    @if ($label)
        <label for="{{ $editorId }}" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {{ $label }}
        </label>
    @endif

    {{-- The toolbar is inert without JS: these buttons manipulate a text
         selection, which is a browser concern. Hidden rather than shown-broken,
         and the textarea below works regardless. --}}
    @unless ($toolbar->isEmpty())
        <div
            class="flex flex-wrap gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-1
                   dark:border-zinc-700 dark:bg-zinc-800/50"
            data-squidink-toolbar
            role="toolbar"
            aria-label="{{ __('Formatting') }}"
            hidden
        >
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

    <textarea
        id="{{ $editorId }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        data-squidink-input
        class="{{ $controlClasses }}"
    >{{ $value }}</textarea>

    {{-- No preview here, deliberately. Previewing means rendering, and rendering
         must go through the real HtmlRenderer — a JavaScript preview would be a
         second implementation that drifts from it, which is the failure this
         package exists to prevent. That needs a server round trip, so it lives in
         the Livewire component: <livewire:squidink-editor />.

         An empty preview pane that nothing ever fills would be worse than none. --}}

    {{-- $errors is only bound when a view renders through the web middleware
         stack, so a component rendered standalone (or in a test) has none. --}}
    @isset($errors)
        @error($name)
            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
    @endisset
</div>
