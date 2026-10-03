<div>
    @include('usarrs::partials.announce-key', ['announceKey' => $announceKey, 'allowRegen' => $allowRegen, 'addressUnproven' => $addressUnproven])

    @if (session('status'))
        <x-deck::text class="mt-2 text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
    @endif
</div>
