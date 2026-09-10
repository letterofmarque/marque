<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-deck::heading size="xl">{{ __('Reset Password') }}</x-deck::heading>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <x-deck::field :label="__('Email')" name="email">
                <x-deck::input name="email" type="email" value="{{ old('email', $email) }}" required />
            </x-deck::field>

            <x-deck::field :label="__('New Password')" name="password">
                <x-deck::input name="password" type="password" required />
            </x-deck::field>

            <x-deck::field :label="__('Confirm Password')">
                <x-deck::input name="password_confirmation" type="password" required />
            </x-deck::field>

            <x-deck::button type="submit" variant="primary" class="w-full">
                {{ __('Reset Password') }}
            </x-deck::button>
        </form>
    </div>
</div>
