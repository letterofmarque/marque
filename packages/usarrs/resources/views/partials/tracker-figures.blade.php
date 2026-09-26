{{--
    Uploaded, downloaded and ratio from a TrackerStats. Shared by /profile/stats
    and the dashboard panel so the two cannot drift apart. A null ratio means
    nothing downloaded, which is infinite, never 0.00.

    $itemClass lets the stats page card each figure while the dashboard panel,
    already a card itself, does not nest one inside another.
--}}
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
</div>
