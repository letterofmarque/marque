{{--
    Uploaded, downloaded, ratio, seed time and leech time from a TrackerStats. Shared by /profile/stats
    and the dashboard panel so the two cannot drift apart. A null ratio means
    nothing downloaded, which is infinite, never 0.00.

    $itemClass lets the stats page card each figure while the dashboard panel,
    already a card itself, does not nest one inside another.

    Above the figures, where the member stands against the tracker's ratio rule
    (#10732), in the tracker's own words. Ok says nothing.
--}}
@php($standing = $stats->standing)
@if ($standing->state === \Marque\Trove\Enums\StandingState::Watch)
    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950">
        <x-deck::text class="font-medium text-amber-900 dark:text-amber-200">{{ __('Ratio watch') }}</x-deck::text>
        <x-deck::text class="mt-1 text-sm text-amber-900 dark:text-amber-200">{{ $standing->reason }}</x-deck::text>
    </div>
@elseif ($standing->state === \Marque\Trove\Enums\StandingState::Restricted)
    <div class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 dark:border-red-700 dark:bg-red-950">
        <x-deck::text class="font-medium text-red-900 dark:text-red-200">{{ __('Downloads paused') }}</x-deck::text>
        <x-deck::text class="mt-1 text-sm text-red-900 dark:text-red-200">{{ $standing->reason }}</x-deck::text>
    </div>
@elseif ($standing->state === \Marque\Trove\Enums\StandingState::Exempt)
    <x-deck::text class="mb-4 text-sm text-zinc-500">{{ __('You are exempt from the ratio rule.') }}</x-deck::text>
@endif
<div class="grid gap-4 sm:grid-cols-3">
    <div class="{{ $itemClass ?? '' }}">
        <x-deck::text class="text-sm text-zinc-500">{{ __('Uploaded') }}</x-deck::text>
        <x-deck::heading size="lg" class="mt-1">{{ Number::fileSize($stats->uploaded) }}</x-deck::heading>
    </div>
    <div class="{{ $itemClass ?? '' }}">
        <x-deck::text class="text-sm text-zinc-500">{{ __('Downloaded') }}</x-deck::text>
        <x-deck::heading size="lg" class="mt-1">{{ Number::fileSize($stats->downloaded) }}</x-deck::heading>
    </div>
    <div class="{{ $itemClass ?? '' }}">
        <x-deck::text class="text-sm text-zinc-500">{{ __('Ratio') }}</x-deck::text>
        <x-deck::heading size="lg" class="mt-1">{{ $stats->hasInfiniteRatio() ? '∞' : number_format($stats->ratio, 2) }}</x-deck::heading>
    </div>
    <div class="{{ $itemClass ?? '' }}">
        <x-deck::text class="text-sm text-zinc-500">{{ __('Seed time') }}</x-deck::text>
        <x-deck::heading size="lg" class="mt-1">{{ \Marque\Usarrs\Support\Duration::short($stats->seedtime) }}</x-deck::heading>
    </div>
    <div class="{{ $itemClass ?? '' }}">
        <x-deck::text class="text-sm text-zinc-500">{{ __('Leech time') }}</x-deck::text>
        <x-deck::heading size="lg" class="mt-1">{{ \Marque\Usarrs\Support\Duration::short($stats->leechtime) }}</x-deck::heading>
    </div>
</div>
