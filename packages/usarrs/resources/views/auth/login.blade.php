<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-deck::heading size="xl">{{ __('Log In') }}</x-deck::heading>
            <x-deck::text class="mt-2 text-zinc-500">
                {{ __('Sign in to your account') }}
            </x-deck::text>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
            </div>
        @endif

        @if ($driver->value === 'socialite')
            <div class="space-y-3">
                @foreach (config('usarrs.socialite_providers', []) as $provider)
                    <x-deck::button variant="outline" class="w-full" :href="route('socialite.redirect', $provider)">
                        {{ __('Continue with :provider', ['provider' => ucfirst($provider)]) }}
                    </x-deck::button>
                @endforeach
            </div>
        @else
            <form wire:submit="login" class="space-y-6">
                <x-deck::field :label="__('Email')" name="email">
                    <x-deck::input wire:model="email" type="email" required autofocus />
                </x-deck::field>

                @if ($driver->requiresPassword())
                    <x-deck::field :label="__('Password')" name="password">
                        <x-deck::input wire:model="password" type="password" required />
                    </x-deck::field>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="remember" class="rounded border-zinc-300 dark:border-zinc-600">
                            <x-deck::text class="text-sm">{{ __('Remember me') }}</x-deck::text>
                        </label>

                        @if ($driver->supportsPasswordReset())
                            <a href="{{ route('password.request') }}" class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200" wire:navigate>
                                {{ __('Forgot password?') }}
                            </a>
                        @endif
                    </div>
                @endif

                <x-deck::button type="submit" variant="primary" class="w-full">
                    @if ($driver->value === 'magic_link')
                        {{ __('Send Login Link') }}
                    @else
                        {{ __('Log In') }}
                    @endif
                </x-deck::button>
            </form>

            @if ($driver->supportsRegistration())
                <x-deck::text class="text-center text-sm text-zinc-500">
                    {{ __("Don't have an account?") }}
                    <a href="{{ route('register') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100" wire:navigate>
                        {{ __('Register') }}
                    </a>
                </x-deck::text>
            @endif
        @endif

        @if (config('usarrs.passkeys.enabled', false) && Route::has('passkey.login'))
            {{-- A passkey signs in under every driver (see the README's
                 Passkeys section). The endpoints are laravel/passkeys',
                 registered by usarrs (#10883). --}}
            <div x-data="usarrsPasskeySignIn()" class="space-y-2">
                <x-deck::button variant="outline" class="w-full" x-on:click="signIn">
                    {{ __('Sign in with a passkey') }}
                </x-deck::button>
                <x-deck::text class="text-center text-sm text-red-600" x-show="error" x-text="error" x-cloak></x-deck::text>
            </div>

            <script>
                function usarrsPasskeySignIn() {
                    return {
                        error: null,

                        async signIn() {
                            this.error = null;

                            try {
                                const optionsResponse = await fetch(@js(route('passkey.login-options')), {
                                    headers: { 'Accept': 'application/json' },
                                });
                                const { options } = await optionsResponse.json();

                                const credential = await navigator.credentials.get({
                                    publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options),
                                });

                                const response = await fetch(@js(route('passkey.login')), {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    },
                                    body: JSON.stringify({ credential: credential.toJSON() }),
                                });

                                const data = await response.json();

                                if (! response.ok) {
                                    throw new Error(data.message);
                                }

                                window.location = data.redirect;
                            } catch (e) {
                                this.error = e.message || @js(__('Passkey sign-in failed.'));
                            }
                        },
                    };
                }
            </script>
        @endif
    </div>
</div>
