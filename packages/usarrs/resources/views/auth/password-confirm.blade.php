<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-deck::heading size="xl">{{ __('Confirm Password') }}</x-deck::heading>
            <x-deck::text class="mt-2 text-zinc-500">
                {{ __('For your security, please confirm your password to continue.') }}
            </x-deck::text>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-deck::field :label="__('Password')" name="password">
                <x-deck::input wire:model="password" type="password" autofocus />
            </x-deck::field>

            <x-deck::button wire:click="confirm" variant="primary" class="mt-4 w-full">
                {{ __('Confirm') }}
            </x-deck::button>
        </div>
    </div>
</div>
