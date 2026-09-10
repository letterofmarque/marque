<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div class="max-w-2xl">
        <x-deck::heading size="xl" class="mb-6">{{ __('Edit Profile') }}</x-deck::heading>

        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
            </div>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <x-deck::field :label="__('Name')" name="name">
                <x-deck::input wire:model="name" required />
            </x-deck::field>

            <x-deck::field :label="__('Email')" name="email">
                <x-deck::input wire:model="email" type="email" required />
            </x-deck::field>

            <x-deck::field :label="__('Bio')" name="bio">
                <x-deck::textarea wire:model="bio" placeholder="{{ __('Tell us about yourself...') }}" rows="3" />
            </x-deck::field>

            <x-deck::field :label="__('New Password')" name="password">
                <x-deck::input wire:model="password" type="password" placeholder="{{ __('Leave blank to keep current') }}" />
            </x-deck::field>

            <x-deck::field :label="__('Confirm New Password')">
                <x-deck::input wire:model="password_confirmation" type="password" />
            </x-deck::field>

            <div class="flex gap-2">
                <x-deck::button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </x-deck::button>
                <x-deck::button variant="ghost" :href="route('profile.show')" wire:navigate>
                    {{ __('Cancel') }}
                </x-deck::button>
            </div>
        </form>
    </div>
</div>
