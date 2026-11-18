<div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
    <x-deck::heading size="lg">{{ __('Other browsers') }}</x-deck::heading>
    <x-deck::text class="mt-1 text-sm text-zinc-500">
        {{ __('Signed in somewhere you no longer use, or somewhere that wasn\'t you? Sign out everywhere but here. Your password doesn\'t change.') }}
    </x-deck::text>

    @if ($done)
        <x-deck::text class="mt-3 text-sm text-green-700 dark:text-green-300">
            {{ __('Every other browser has been signed out.') }}
        </x-deck::text>
    @endif

    <div class="mt-4">
        <x-deck::button variant="outline" size="sm" wire:click="endOthers"
            wire:confirm="{{ __('Sign out every other browser? Each one will need to sign in again.') }}">
            {{ __('Sign out every other browser') }}
        </x-deck::button>
    </div>
</div>
