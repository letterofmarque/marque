<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-deck::heading size="xl">{{ __('Create Account') }}</x-deck::heading>
            <x-deck::text class="mt-2 text-zinc-500">
                {{ __('Register for a new account') }}
            </x-deck::text>
        </div>

        <form wire:submit="register" class="space-y-6">
            @if ($inviteRequired)
                <x-deck::field :label="__('Invite Code')" name="invite">
                    <x-deck::input wire:model="invite" required />
                </x-deck::field>
            @endif

            <x-deck::field :label="__('Name')" name="name">
                <x-deck::input wire:model="name" required autofocus />
            </x-deck::field>

            <x-deck::field :label="__('Email')" name="email">
                <x-deck::input wire:model="email" type="email" required />
            </x-deck::field>

            <x-deck::field :label="__('Password')" name="password">
                <x-deck::input wire:model="password" type="password" required />
            </x-deck::field>

            <x-deck::field :label="__('Confirm Password')">
                <x-deck::input wire:model="password_confirmation" type="password" required />
            </x-deck::field>

            <x-deck::button type="submit" variant="primary" class="w-full">
                {{ __('Register') }}
            </x-deck::button>
        </form>

        <x-deck::text class="text-center text-sm text-zinc-500">
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100" wire:navigate>
                {{ __('Log in') }}
            </a>
        </x-deck::text>
    </div>
</div>
