<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-id::heading size="xl">{{ __('Reset Password') }}</x-id::heading>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <x-id::field :label="__('Email')" name="email">
                <x-id::input name="email" type="email" value="{{ old('email', $email) }}" required />
            </x-id::field>

            <x-id::field :label="__('New Password')" name="password">
                <x-id::input name="password" type="password" required />
            </x-id::field>

            <x-id::field :label="__('Confirm Password')">
                <x-id::input name="password_confirmation" type="password" required />
            </x-id::field>

            <x-id::button type="submit" variant="primary" class="w-full">
                {{ __('Reset Password') }}
            </x-id::button>
        </form>
    </div>
</div>
