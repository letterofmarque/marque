<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('invites.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div class="max-w-2xl">
        <x-deck::heading size="xl" class="mb-6">{{ __('Create Invite') }}</x-deck::heading>

        <form wire:submit="create" class="flex flex-col gap-6">
            <x-deck::field :label="__('Recipient Email')" name="recipientEmail">
                <x-deck::input
                    wire:model="recipientEmail"
                    type="email"
                    placeholder="{{ __('Optional - leave blank for a general invite') }}"
                />
            </x-deck::field>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <x-deck::text class="text-sm text-zinc-500">
                    {{ __('The invite will expire in :days days.', ['days' => config('usarrs.invites.expiry_days', 7)]) }}
                    @if ($recipientEmail)
                        {{ __('An email notification will be sent to the recipient.') }}
                    @endif
                </x-deck::text>
            </div>

            <div class="flex gap-2">
                <x-deck::button type="submit" variant="primary">
                    {{ __('Create Invite') }}
                </x-deck::button>
                <x-deck::button variant="ghost" :href="route('invites.index')" wire:navigate>
                    {{ __('Cancel') }}
                </x-deck::button>
            </div>
        </form>
    </div>
</div>
