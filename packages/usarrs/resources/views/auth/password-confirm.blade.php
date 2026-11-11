<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-6">
        <div class="text-center">
            <x-deck::heading size="xl">{{ __('Confirm it\'s you') }}</x-deck::heading>
            <x-deck::text class="mt-2 text-zinc-500">
                {{ __('For your security, confirm it\'s you to continue.') }}
            </x-deck::text>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
            </div>
        @endif

        {{-- A provider round trip that didn't confirm lands back here with why. --}}
        <x-deck::error name="provider" />

        {{-- Each way of confirming the member actually has (Job #355). --}}

        @if ($offersPassword)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-deck::field :label="__('Password')" name="password">
                    <x-deck::input wire:model="password" type="password" autofocus />
                </x-deck::field>

                <x-deck::button wire:click="confirm" variant="primary" class="mt-4 w-full">
                    {{ __('Confirm') }}
                </x-deck::button>
            </div>
        @endif

        @foreach ($providers as $provider)
            <form method="POST" action="{{ route('socialite.confirm', $provider) }}">
                @csrf
                <x-deck::button type="submit" variant="outline" class="w-full">
                    {{ __('Confirm with :provider', ['provider' => ucfirst($provider)]) }}
                </x-deck::button>
            </form>
        @endforeach

        @if ($passkeyUrls)
            <div x-data="usarrsPasskeyConfirm()">
                <x-deck::button x-on:click="confirm" variant="outline" class="w-full">
                    {{ __('Confirm with a passkey') }}
                </x-deck::button>
                <x-deck::text class="mt-2 text-sm text-red-600" x-show="error" x-text="error" x-cloak></x-deck::text>

                {{-- The browser side of laravel/passkeys' confirm ceremony
                     (navigator.credentials.get), which marks the session confirmed
                     and answers with where to go. The suite drives the endpoints
                     with a software authenticator (PasskeyStepUpTest). --}}
                <script>
                    function usarrsPasskeyConfirm() {
                        return {
                            error: null,

                            async confirm() {
                                this.error = null;

                                try {
                                    const optionsResponse = await fetch(@js($passkeyUrls['options']), {
                                        headers: { 'Accept': 'application/json' },
                                    });
                                    const optionsData = await optionsResponse.json();

                                    if (! optionsResponse.ok) {
                                        throw new Error(optionsData.message);
                                    }

                                    const credential = await navigator.credentials.get({
                                        publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(optionsData.options),
                                    });

                                    const response = await fetch(@js($passkeyUrls['confirm']), {
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
                                    this.error = e.message || @js(__('The passkey could not be used.'));
                                }
                            },
                        };
                    }
                </script>
            </div>
        @endif

        @if ($needsVerification)
            {{-- Without a verified address there's no emailed link, and under
                 magic_link or socialite perhaps nothing else either (Job #355). --}}
            <x-deck::text class="text-center text-sm text-zinc-500">
                {{ __('Verify your email address first, and you can confirm it\'s you by email.') }}
                <a href="{{ route('verification.notice') }}" class="underline">{{ __('Verify your email address') }}</a>
            </x-deck::text>
        @endif

        @if ($offersLink)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900"
                @if ($linkOut) wire:poll.3s="checkLink" @endif>
                @if ($linkOut)
                    <x-deck::text class="text-sm text-zinc-500">
                        {{ __('Waiting for you to approve the link we emailed you. This page carries on by itself.') }}
                    </x-deck::text>
                @endif

                <x-deck::button wire:click="sendLink" variant="ghost" class="mt-2 w-full">
                    {{ $linkOut ? __('Send another link') : __('Email me a link') }}
                </x-deck::button>

                <x-deck::error name="link" />
            </div>
        @endif
    </div>
</div>
