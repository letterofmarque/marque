<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-id::heading size="xl">{{ __('Log In') }}</x-id::heading>
            <x-id::text class="mt-2 text-zinc-500">
                {{ __('Sign in to your account') }}
            </x-id::text>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-id::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-id::text>
            </div>
        @endif

        @if ($driver->value === 'socialite')
            <div class="space-y-3">
                @foreach (config('usarrs.socialite_providers', []) as $provider)
                    <x-id::button variant="outline" class="w-full" :href="route('socialite.redirect', $provider)">
                        {{ __('Continue with :provider', ['provider' => ucfirst($provider)]) }}
                    </x-id::button>
                @endforeach
            </div>
        @else
            <form wire:submit="login" class="space-y-6">
                <x-id::field :label="__('Email')" name="email">
                    <x-id::input wire:model="email" type="email" required autofocus />
                </x-id::field>

                @if ($driver->requiresPassword())
                    <x-id::field :label="__('Password')" name="password">
                        <x-id::input wire:model="password" type="password" required />
                    </x-id::field>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="remember" class="rounded border-zinc-300 dark:border-zinc-600">
                            <x-id::text class="text-sm">{{ __('Remember me') }}</x-id::text>
                        </label>

                        @if ($driver->supportsPasswordReset())
                            <a href="{{ route('password.request') }}" class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200" wire:navigate>
                                {{ __('Forgot password?') }}
                            </a>
                        @endif
                    </div>
                @endif

                <x-id::button type="submit" variant="primary" class="w-full">
                    @if ($driver->value === 'magic_link')
                        {{ __('Send Login Link') }}
                    @else
                        {{ __('Log In') }}
                    @endif
                </x-id::button>
            </form>

            @if ($driver->supportsRegistration())
                <x-id::text class="text-center text-sm text-zinc-500">
                    {{ __("Don't have an account?") }}
                    <a href="{{ route('register') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100" wire:navigate>
                        {{ __('Register') }}
                    </a>
                </x-id::text>
            @endif
        @endif
    </div>
</div>
