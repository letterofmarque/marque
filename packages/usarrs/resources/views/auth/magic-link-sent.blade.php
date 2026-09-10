<div class="flex min-h-full items-center justify-center py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8 text-center">
        <x-deck::heading size="xl">{{ __('Check Your Email') }}</x-deck::heading>
        <x-deck::text class="text-zinc-500">
            {{ __('We\'ve sent a login link to your email address. Click the link to sign in.') }}
        </x-deck::text>
        <x-deck::text class="text-sm text-zinc-400">
            {{ __('The link expires in 15 minutes.') }}
        </x-deck::text>
        <a href="{{ route('login') }}" class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200">
            {{ __('Back to login') }}
        </a>
    </div>
</div>
