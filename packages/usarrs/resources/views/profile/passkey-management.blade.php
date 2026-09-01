<div class="flex h-full w-full flex-1 flex-col gap-6" x-data="usarrsPasskeys()">
    <x-ise::heading size="xl">{{ __('Passkeys') }}</x-ise::heading>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-ise::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-ise::text>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        @forelse ($passkeys as $passkey)
            <div class="flex items-center justify-between py-2">
                <x-ise::text>{{ $passkey->name }}</x-ise::text>
                <x-ise::button variant="ghost" size="sm" wire:click="delete({{ $passkey->id }})"
                    wire:confirm="{{ __('Remove this passkey?') }}">
                    {{ __('Remove') }}
                </x-ise::button>
            </div>
        @empty
            <x-ise::text class="text-sm text-zinc-500">{{ __('No passkeys registered yet.') }}</x-ise::text>
        @endforelse
    </div>

    <x-ise::button x-on:click="register">{{ __('Add a Passkey') }}</x-ise::button>

    {{-- The actual WebAuthn ceremony (navigator.credentials.create) talks
         directly to Passkeys' own JSON endpoints (/user/passkeys/options,
         /user/passkeys) — usarrs deliberately leaves those routes registered
         (see UsarrsServiceProvider) rather than reimplementing them. This
         script is the browser-side glue; it cannot be exercised by the
         headless test suite (see PasskeyManagementTest.php's header comment)
         and needs a manual or Playwright pass against a real browser. --}}
    <script>
        function usarrsPasskeys() {
            return {
                async register() {
                    const optionsResponse = await fetch('/user/passkeys/options');
                    const options = await optionsResponse.json();

                    const credential = await navigator.credentials.create({
                        publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options),
                    });

                    await fetch('/user/passkeys', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({
                            name: prompt('{{ __('Name this passkey') }}', 'My Passkey'),
                            credential: credential.toJSON(),
                        }),
                    });

                    window.location.reload();
                },
            };
        }
    </script>
</div>
