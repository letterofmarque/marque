<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <div class="max-w-2xl">
        <x-id::heading size="xl" class="mb-6">{{ __('Edit Profile') }}</x-id::heading>

        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-id::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-id::text>
            </div>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <x-id::field :label="__('Name')" name="name">
                <x-id::input wire:model="name" required />
            </x-id::field>

            <x-id::field :label="__('Email')" name="email">
                <x-id::input wire:model="email" type="email" required />
            </x-id::field>

            <x-id::field :label="__('Bio')" name="bio">
                <x-id::textarea wire:model="bio" placeholder="{{ __('Tell us about yourself...') }}" rows="3" />
            </x-id::field>

            <x-id::field :label="__('New Password')" name="password">
                <x-id::input wire:model="password" type="password" placeholder="{{ __('Leave blank to keep current') }}" />
            </x-id::field>

            <x-id::field :label="__('Confirm New Password')">
                <x-id::input wire:model="password_confirmation" type="password" />
            </x-id::field>

            <div class="flex gap-2">
                <x-id::button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </x-id::button>
                <x-id::button variant="ghost" :href="route('profile.show')" wire:navigate>
                    {{ __('Cancel') }}
                </x-id::button>
            </div>
        </form>
    </div>
</div>
