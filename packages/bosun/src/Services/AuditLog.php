<?php

declare(strict_types=1);

namespace Marque\Bosun\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Marque\Bosun\Models\StaffActionEntry;

/**
 * Reading the staff action log: filtered lists, and how much each staff member
 * has done. The Audit log and Staff screens both read through here.
 */
class AuditLog
{
    /**
     * @param  array{actor?: int|string|null, action?: string|null, subject_type?: string|null, subject_id?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<StaffActionEntry>
     */
    public function query(array $filters = []): Builder
    {
        $filled = fn (string $key): bool => isset($filters[$key]) && $filters[$key] !== '';

        return StaffActionEntry::query()
            ->with('actor')
            ->when($filled('actor'), fn (Builder $q) => $q->where('actor_id', (int) $filters['actor']))
            ->when($filled('action'), fn (Builder $q) => $q->where('action', $filters['action']))
            ->when($filled('subject_type'), fn (Builder $q) => $q->where('subject_type', $filters['subject_type']))
            ->when($filled('subject_id'), fn (Builder $q) => $q->where('subject_id', (string) $filters['subject_id']))
            ->when($filled('from'), fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filled('to'), fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * How many actions each of these staff members took in the last N days,
     * for each window.
     *
     * @param  list<int>  $actorIds
     * @param  list<int>  $windows  days
     * @return array<int, array<int, int>> actor id => [days => count]
     */
    public function counts(array $actorIds, array $windows = [7, 30, 90]): array
    {
        $counts = array_fill_keys($actorIds, array_fill_keys($windows, 0));

        if ($actorIds === []) {
            return $counts;
        }

        foreach ($windows as $days) {
            $rows = StaffActionEntry::query()
                ->selectRaw('actor_id, count(*) as total')
                ->whereIn('actor_id', $actorIds)
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('actor_id')
                ->pluck('total', 'actor_id');

            foreach ($rows as $actorId => $total) {
                $counts[(int) $actorId][$days] = (int) $total;
            }
        }

        return $counts;
    }

    /**
     * Every action name in the log, for a filter.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return StaffActionEntry::query()->distinct()->orderBy('action')->pluck('action')->all();
    }
}
