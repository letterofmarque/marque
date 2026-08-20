<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <div class="text-center">
            <x-ise::heading size="xl">{{ __('Forgot Password') }}</x-ise::heading>
            <x-ise::text class="mt-2 text-zinc-500">
                {{ __('Enter your email and we\'ll send you a reset link.') }}
            </x-ise::text>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-ise::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-ise::text>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
            @csrf

            <x-ise::field :label="__('Email')" name="email">
                <x-ise::input name="email" type="email" value="{{ old('email') }}" required autofocus />
            </x-ise::field>

            <x-ise::button type="submit" variant="primary" class="w-full">
                {{ __('Send Reset Link') }}
            </x-ise::button>
        </form>

        <x-ise::text class="text-center text-sm text-zinc-500">
            <a href="{{ route('login') }}" class="text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">
                {{ __('Back to login') }}
            </a>
        </x-ise::text>
    </div>
</div>
