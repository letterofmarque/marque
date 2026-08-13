<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('invites.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <div class="max-w-2xl">
        <x-id::heading size="xl" class="mb-6">{{ __('Create Invite') }}</x-id::heading>

        <form wire:submit="create" class="flex flex-col gap-6">
            <x-id::field :label="__('Recipient Email')" name="recipientEmail">
                <x-id::input
                    wire:model="recipientEmail"
                    type="email"
                    placeholder="{{ __('Optional - leave blank for a general invite') }}"
                />
            </x-id::field>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <x-id::text class="text-sm text-zinc-500">
                    {{ __('The invite will expire in :days days.', ['days' => config('usarrs.invites.expiry_days', 7)]) }}
                    @if ($recipientEmail)
                        {{ __('An email notification will be sent to the recipient.') }}
                    @endif
                </x-id::text>
            </div>

            <div class="flex gap-2">
                <x-id::button type="submit" variant="primary">
                    {{ __('Create Invite') }}
                </x-id::button>
                <x-id::button variant="ghost" :href="route('invites.index')" wire:navigate>
                    {{ __('Cancel') }}
                </x-id::button>
            </div>
        </form>
    </div>
</div>
