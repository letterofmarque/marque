<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-ise::heading size="xl">{{ __('Create Account') }}</x-ise::heading>
            <x-ise::text class="mt-2 text-zinc-500">
                {{ __('Register for a new account') }}
            </x-ise::text>
        </div>

        <form wire:submit="register" class="space-y-6">
            @if ($inviteRequired)
                <x-ise::field :label="__('Invite Code')" name="invite">
                    <x-ise::input wire:model="invite" required />
                </x-ise::field>
            @endif

            <x-ise::field :label="__('Name')" name="name">
                <x-ise::input wire:model="name" required autofocus />
            </x-ise::field>

            <x-ise::field :label="__('Email')" name="email">
                <x-ise::input wire:model="email" type="email" required />
            </x-ise::field>

            <x-ise::field :label="__('Password')" name="password">
                <x-ise::input wire:model="password" type="password" required />
            </x-ise::field>

            <x-ise::field :label="__('Confirm Password')">
                <x-ise::input wire:model="password_confirmation" type="password" required />
            </x-ise::field>

            <x-ise::button type="submit" variant="primary" class="w-full">
                {{ __('Register') }}
            </x-ise::button>
        </form>

        <x-ise::text class="text-center text-sm text-zinc-500">
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100" wire:navigate>
                {{ __('Log in') }}
            </a>
        </x-ise::text>
    </div>
</div>
