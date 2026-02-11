<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <flux:heading size="xl">{{ __('Forgot Password') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">
                {{ __('Enter your email and we\'ll send you a reset link.') }}
            </flux:text>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <flux:text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</flux:text>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
            @csrf

            <flux:field>
                <flux:label>{{ __('Email') }}</flux:label>
                <flux:input name="email" type="email" value="{{ old('email') }}" required autofocus />
                @error('email') <flux:error>{{ $message }}</flux:error> @enderror
            </flux:field>

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Send Reset Link') }}
            </flux:button>
        </form>

        <flux:text class="text-center text-sm text-zinc-500">
            <a href="{{ route('login') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">
                {{ __('Back to login') }}
            </a>
        </flux:text>
    </div>
</div>
