<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-ise::button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-ise::button>
    </div>

    <div class="max-w-2xl">
        <x-ise::heading size="xl" class="mb-6">{{ __('Edit Profile') }}</x-ise::heading>

        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-ise::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-ise::text>
            </div>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <x-ise::field :label="__('Name')" name="name">
                <x-ise::input wire:model="name" required />
            </x-ise::field>

            <x-ise::field :label="__('Email')" name="email">
                <x-ise::input wire:model="email" type="email" required />
            </x-ise::field>

            <x-ise::field :label="__('Bio')" name="bio">
                <x-ise::textarea wire:model="bio" placeholder="{{ __('Tell us about yourself...') }}" rows="3" />
            </x-ise::field>

            <x-ise::field :label="__('New Password')" name="password">
                <x-ise::input wire:model="password" type="password" placeholder="{{ __('Leave blank to keep current') }}" />
            </x-ise::field>

            <x-ise::field :label="__('Confirm New Password')">
                <x-ise::input wire:model="password_confirmation" type="password" />
            </x-ise::field>

            <div class="flex gap-2">
                <x-ise::button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </x-ise::button>
                <x-ise::button variant="ghost" :href="route('profile.show')" wire:navigate>
                    {{ __('Cancel') }}
                </x-ise::button>
            </div>
        </form>
    </div>
</div>
