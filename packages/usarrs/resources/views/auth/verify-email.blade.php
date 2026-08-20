<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8 text-center">
        <x-ise::heading size="xl">{{ __('Verify Your Email') }}</x-ise::heading>
        <x-ise::text class="text-zinc-500">
            {{ __('We\'ve sent a verification link to your email address. Please check your inbox.') }}
        </x-ise::text>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-ise::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-ise::text>
            </div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ise::button type="submit" variant="primary">
                {{ __('Resend Verification Email') }}
            </x-ise::button>
        </form>
    </div>
</div>
