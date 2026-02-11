<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <flux:heading size="xl">{{ __('Create Account') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">
                {{ __('Register for a new account') }}
            </flux:text>
        </div>

        <form wire:submit="register" class="space-y-6">
            @if ($inviteRequired)
                <flux:field>
                    <flux:label>{{ __('Invite Code') }}</flux:label>
                    <flux:input wire:model="invite" required />
                    <flux:error name="invite" />
                </flux:field>
            @endif

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" required autofocus />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Email') }}</flux:label>
                <flux:input wire:model="email" type="email" required />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Password') }}</flux:label>
                <flux:input wire:model="password" type="password" required />
                <flux:error name="password" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Confirm Password') }}</flux:label>
                <flux:input wire:model="password_confirmation" type="password" required />
            </flux:field>

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Register') }}
            </flux:button>
        </form>

        <flux:text class="text-center text-sm text-zinc-500">
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100" wire:navigate>
                {{ __('Log in') }}
            </a>
        </flux:text>
    </div>
</div>
