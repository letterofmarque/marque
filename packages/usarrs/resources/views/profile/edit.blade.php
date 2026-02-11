<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <flux:button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <div class="max-w-2xl">
        <flux:heading size="xl" class="mb-6">{{ __('Edit Profile') }}</flux:heading>

        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <flux:text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</flux:text>
            </div>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Email') }}</flux:label>
                <flux:input wire:model="email" type="email" required />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Bio') }}</flux:label>
                <flux:textarea wire:model="bio" placeholder="{{ __('Tell us about yourself...') }}" rows="3" />
                <flux:error name="bio" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('New Password') }}</flux:label>
                <flux:input wire:model="password" type="password" placeholder="{{ __('Leave blank to keep current') }}" />
                <flux:error name="password" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Confirm New Password') }}</flux:label>
                <flux:input wire:model="password_confirmation" type="password" />
            </flux:field>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </flux:button>
                <flux:button variant="ghost" :href="route('profile.show')" wire:navigate>
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</div>
