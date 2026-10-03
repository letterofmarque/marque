<div class="flex h-full w-full flex-1 flex-col gap-6" x-data="usarrsPasskeys()">
    <x-deck::heading size="xl">{{ __('Passkeys') }}</x-deck::heading>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        @forelse ($passkeys as $passkey)
            <div class="flex items-center justify-between py-2">
                <x-deck::text>{{ $passkey->name }}</x-deck::text>
                <x-deck::button variant="ghost" size="sm" wire:click="delete({{ $passkey->id }})"
                    wire:confirm="{{ __('Remove this passkey?') }}">
                    {{ __('Remove') }}
                </x-deck::button>
            </div>
        @empty
            <x-deck::text class="text-sm text-zinc-500">{{ __('No passkeys registered yet.') }}</x-deck::text>
        @endforelse
    </div>

    <x-deck::button x-on:click="register">{{ __('Add a Passkey') }}</x-deck::button>
    <x-deck::text class="text-sm text-red-600" x-show="error" x-text="error" x-cloak></x-deck::text>

    {{-- The browser side of the WebAuthn ceremony (navigator.credentials.create)
         against laravel/passkeys' endpoints, which usarrs registers itself
         (routes/auth.php, #10883). The suite drives those endpoints with a
         software authenticator (PasskeyCeremonyTest); this script still needs a
         real browser to exercise. --}}
    <script>
        function usarrsPasskeys() {
            return {
                error: null,

                async register() {
                    this.error = null;

                    try {
                        const optionsResponse = await fetch(@js($optionsUrl), {
                            headers: { 'Accept': 'application/json' },
                        });

                        // 423: the password needs confirming first. Go and do
                        // that, and come back here.
                        if (optionsResponse.status === 423) {
                            return this.$wire.requirePasswordConfirmation();
                        }

                        const optionsData = await optionsResponse.json();

                        if (! optionsResponse.ok) {
                            throw new Error(optionsData.message);
                        }

                        const { options } = optionsData;

                        const credential = await navigator.credentials.create({
                            publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options),
                        });

                        const response = await fetch(@js($storeUrl), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                name: prompt(@js(__('Name this passkey')), 'My Passkey') || 'My Passkey',
                                credential: credential.toJSON(),
                            }),
                        });

                        if (! response.ok) {
                            throw new Error((await response.json()).message);
                        }

                        window.location.reload();
                    } catch (e) {
                        this.error = e.message || @js(__('The passkey could not be added.'));
                    }
                },
            };
        }
    </script>
</div>
