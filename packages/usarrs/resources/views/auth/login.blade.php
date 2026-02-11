<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <flux:heading size="xl">{{ __('Log In') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">
                {{ __('Sign in to your account') }}
            </flux:text>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <flux:text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</flux:text>
            </div>
        @endif

        @if ($driver->value === 'socialite')
            <div class="space-y-3">
                @foreach (config('usarrs.socialite_providers', []) as $provider)
                    <flux:button variant="outline" class="w-full" :href="route('socialite.redirect', $provider)">
                        {{ __('Continue with :provider', ['provider' => ucfirst($provider)]) }}
                    </flux:button>
                @endforeach
            </div>
        @else
            <form wire:submit="login" class="space-y-6">
                <flux:field>
                    <flux:label>{{ __('Email') }}</flux:label>
                    <flux:input wire:model="email" type="email" required autofocus />
                    <flux:error name="email" />
                </flux:field>

                @if ($driver->requiresPassword())
                    <flux:field>
                        <flux:label>{{ __('Password') }}</flux:label>
                        <flux:input wire:model="password" type="password" required />
                        <flux:error name="password" />
                    </flux:field>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="remember" class="rounded border-zinc-300 dark:border-zinc-600">
                            <flux:text class="text-sm">{{ __('Remember me') }}</flux:text>
                        </label>

                        @if ($driver->supportsPasswordReset())
                            <a href="{{ route('password.request') }}" class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200" wire:navigate>
                                {{ __('Forgot password?') }}
                            </a>
                        @endif
                    </div>
                @endif

                <flux:button type="submit" variant="primary" class="w-full">
                    @if ($driver->value === 'magic_link')
                        {{ __('Send Login Link') }}
                    @else
                        {{ __('Log In') }}
                    @endif
                </flux:button>
            </form>

            @if ($driver->supportsRegistration())
                <flux:text class="text-center text-sm text-zinc-500">
                    {{ __("Don't have an account?") }}
                    <a href="{{ route('register') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100" wire:navigate>
                        {{ __('Register') }}
                    </a>
                </flux:text>
            @endif
        @endif
    </div>
</div>
