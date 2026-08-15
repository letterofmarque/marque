/**
 * Progressive enhancement for the SquidInk editor.
 *
 * The server renders a working textarea; this adds the toolbar behaviour on top.
 * Everything it needs was emitted as data- attributes by the Blade component, so
 * this file contains no knowledge of Markdown, BBCode, or any other syntax — the
 * active parser decided what each button inserts. Adding a fourth input format
 * must never require editing this file.
 *
 * Deliberately dependency-free and un-bundled: a text package should not drag in
 * a build step. Publish it and include it with a <script> tag.
 */
(function () {
    'use strict';

    /**
     * Apply one button's insertion to a textarea's current selection.
     *
     * Mirrors Insertion::applyTo() in PHP. The logic is duplicated rather than
     * shared because the two run in different languages, so the PHP side is the
     * specification and is what the tests pin down.
     */
    function insert(textarea, button) {
        var prefix = button.dataset.prefix || '';
        var suffix = button.dataset.suffix || '';
        var placeholder = button.dataset.placeholder || '';
        var isBlock = button.hasAttribute('data-block');

        var value = textarea.value;
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;

        var before = value.slice(0, start);
        var selected = value.slice(start, end);
        var after = value.slice(end);

        // A block construct needs its own line, or "text> quoted" happens.
        if (isBlock && before !== '' && !before.endsWith('\n')) {
            before += '\n';
        }

        var body = selected === '' ? placeholder : selected;

        textarea.value = before + prefix + body + suffix + after;

        // Leave the body selected so typing replaces a placeholder, and a
        // wrapped selection stays wrapped.
        var bodyStart = before.length + prefix.length;

        textarea.focus();
        textarea.setSelectionRange(bodyStart, bodyStart + body.length);

        // Tell any framework bound to this field that the value moved. Without
        // this, Livewire's wire:model never sees a toolbar edit.
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function setup(editor) {
        if (editor.dataset.squidinkReady) {
            return;
        }

        editor.dataset.squidinkReady = '1';

        var textarea = editor.querySelector('[data-squidink-input]');
        var toolbar = editor.querySelector('[data-squidink-toolbar]');

        if (!textarea || !toolbar) {
            return;
        }

        // The toolbar ships hidden so it never appears without the behaviour
        // that makes it work.
        toolbar.hidden = false;

        toolbar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-squidink-action]');

            if (button) {
                event.preventDefault();
                insert(textarea, button);
            }
        });
    }

    function setupAll(root) {
        (root || document).querySelectorAll('[data-squidink-editor]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setupAll();
        });
    } else {
        setupAll();
    }

    // Livewire replaces DOM on update, so re-scan after it morphs. Guarded
    // because Livewire is optional.
    document.addEventListener('livewire:navigated', function () {
        setupAll();
    });

    document.addEventListener('livewire:update', function () {
        setupAll();
    });
})();
