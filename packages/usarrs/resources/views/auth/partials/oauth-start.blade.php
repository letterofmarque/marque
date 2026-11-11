{{--
    Starting an OAuth sign-in or sign-up (#10858). A POST, so CSRF protection
    ties it to this site: an invite and the remember-me choice ride the round
    trip only this way. Each button posts the same form to its own provider.

    $invite: the invite to carry (the /register page), or null. $inviteRequired:
    whether to show the field for typing one.
--}}
<form method="POST" action="{{ route('socialite.redirect', config('usarrs.socialite_providers', [])[0] ?? 'github') }}" class="space-y-4">
    @csrf

    @if (($inviteRequired ?? false) || filled($invite ?? null))
        <x-deck::field :label="__('Invite Code')" name="invite">
            <x-deck::input name="invite" value="{{ $invite ?? '' }}" :required="$inviteRequired ?? false" />
        </x-deck::field>
    @endif

    <label class="flex items-center gap-2">
        <input type="checkbox" name="remember" value="1" class="rounded border-zinc-300 dark:border-zinc-600">
        <x-deck::text class="text-sm">{{ __('Remember me') }}</x-deck::text>
    </label>

    <div class="space-y-3">
        @foreach (config('usarrs.socialite_providers', []) as $provider)
            <x-deck::button type="submit" variant="outline" class="w-full" formaction="{{ route('socialite.redirect', $provider) }}">
                {{ __('Continue with :provider', ['provider' => ucfirst($provider)]) }}
            </x-deck::button>
        @endforeach
    </div>
</form>
