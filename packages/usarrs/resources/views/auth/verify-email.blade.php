<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8 text-center">
        <x-id::heading size="xl">{{ __('Verify Your Email') }}</x-id::heading>
        <x-id::text class="text-zinc-500">
            {{ __('We\'ve sent a verification link to your email address. Please check your inbox.') }}
        </x-id::text>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-id::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-id::text>
            </div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-id::button type="submit" variant="primary">
                {{ __('Resend Verification Email') }}
            </x-id::button>
        </form>
    </div>
</div>
