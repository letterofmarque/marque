<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-ise::heading size="xl">{{ __('Reset Password') }}</x-ise::heading>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <x-ise::field :label="__('Email')" name="email">
                <x-ise::input name="email" type="email" value="{{ old('email', $email) }}" required />
            </x-ise::field>

            <x-ise::field :label="__('New Password')" name="password">
                <x-ise::input name="password" type="password" required />
            </x-ise::field>

            <x-ise::field :label="__('Confirm Password')">
                <x-ise::input name="password_confirmation" type="password" required />
            </x-ise::field>

            <x-ise::button type="submit" variant="primary" class="w-full">
                {{ __('Reset Password') }}
            </x-ise::button>
        </form>
    </div>
</div>
