<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-ise::heading size="xl">{{ __('Confirm Password') }}</x-ise::heading>
            <x-ise::text class="mt-2 text-zinc-500">
                {{ __('For your security, please confirm your password to continue.') }}
            </x-ise::text>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-ise::field :label="__('Password')" name="password">
                <x-ise::input wire:model="password" type="password" autofocus />
            </x-ise::field>

            <x-ise::button wire:click="confirm" variant="primary" class="mt-4 w-full">
                {{ __('Confirm') }}
            </x-ise::button>
        </div>
    </div>
</div>
