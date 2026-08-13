<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-id::heading size="xl">{{ __('Create Account') }}</x-id::heading>
            <x-id::text class="mt-2 text-zinc-500">
                {{ __('Register for a new account') }}
            </x-id::text>
        </div>

        <form wire:submit="register" class="space-y-6">
            @if ($inviteRequired)
                <x-id::field :label="__('Invite Code')" name="invite">
                    <x-id::input wire:model="invite" required />
                </x-id::field>
            @endif

            <x-id::field :label="__('Name')" name="name">
                <x-id::input wire:model="name" required autofocus />
            </x-id::field>

            <x-id::field :label="__('Email')" name="email">
                <x-id::input wire:model="email" type="email" required />
            </x-id::field>

            <x-id::field :label="__('Password')" name="password">
                <x-id::input wire:model="password" type="password" required />
            </x-id::field>

            <x-id::field :label="__('Confirm Password')">
                <x-id::input wire:model="password_confirmation" type="password" required />
            </x-id::field>

            <x-id::button type="submit" variant="primary" class="w-full">
                {{ __('Register') }}
            </x-id::button>
        </form>

        <x-id::text class="text-center text-sm text-zinc-500">
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100" wire:navigate>
                {{ __('Log in') }}
            </a>
        </x-id::text>
    </div>
</div>
