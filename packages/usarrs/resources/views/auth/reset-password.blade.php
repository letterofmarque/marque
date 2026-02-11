<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <flux:heading size="xl">{{ __('Reset Password') }}</flux:heading>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <flux:field>
                <flux:label>{{ __('Email') }}</flux:label>
                <flux:input name="email" type="email" value="{{ old('email', $email) }}" required />
                @error('email') <flux:error>{{ $message }}</flux:error> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('New Password') }}</flux:label>
                <flux:input name="password" type="password" required />
                @error('password') <flux:error>{{ $message }}</flux:error> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Confirm Password') }}</flux:label>
                <flux:input name="password_confirmation" type="password" required />
            </flux:field>

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Reset Password') }}
            </flux:button>
        </form>
    </div>
</div>
