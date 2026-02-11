<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <flux:button variant="ghost" :href="route('invites.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <div class="max-w-2xl">
        <flux:heading size="xl" class="mb-6">{{ __('Create Invite') }}</flux:heading>

        <form wire:submit="create" class="flex flex-col gap-6">
            <flux:field>
                <flux:label>{{ __('Recipient Email') }}</flux:label>
                <flux:input
                    wire:model="recipientEmail"
                    type="email"
                    placeholder="{{ __('Optional - leave blank for a general invite') }}"
                />
                <flux:error name="recipientEmail" />
            </flux:field>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500">
                    {{ __('The invite will expire in :days days.', ['days' => config('usarrs.invites.expiry_days', 7)]) }}
                    @if ($recipientEmail)
                        {{ __('An email notification will be sent to the recipient.') }}
                    @endif
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">
                    {{ __('Create Invite') }}
                </flux:button>
                <flux:button variant="ghost" :href="route('invites.index')" wire:navigate>
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</div>
