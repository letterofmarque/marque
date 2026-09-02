<?php

declare(strict_types=1);

namespace Marque\Trove\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Database\Factories\TorrentFactory;
use Marque\Trove\Enums\Role;

class Torrent extends Model
{
    use HasFactory;

    protected $fillable = [
        'info_hash',
        'name',
        'description',
        'size',
        'file_count',
        'torrent_file',
        'user_id',
        'min_role',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'file_count' => 'integer',
            'seeders' => 'integer',
            'leechers' => 'integer',
            'min_role' => Role::class,
        ];
    }

    /**
     * Restrict a query to torrents the given viewer is allowed to see.
     *
     * This is the enforcement point for min_role, not TorrentPolicy. A policy
     * answers "may this user see this one torrent" and is right for a detail
     * page; it does nothing for a listing, where a restricted torrent would
     * otherwise appear in the index, the API collection and any count. Both
     * exist, and this is the one that has to be applied on every read path.
     *
     * A null viewer is a guest (disguise browsing without auth) and sees only
     * unrestricted torrents.
     *
     * @param  Builder<Torrent>  $query
     */
    public function scopeVisibleTo(Builder $query, ?UserInterface $viewer): void
    {
        $allowed = $viewer === null
            ? []
            : array_map(
                fn (Role $role): string => $role->value,
                array_filter(Role::cases(), fn (Role $role): bool => $viewer->hasRoleAtLeast($role)),
            );

        $query->where(function (Builder $query) use ($allowed): void {
            $query->whereNull('min_role');

            if ($allowed !== []) {
                $query->orWhereIn('min_role', $allowed);
            }
        });
    }

    /**
     * Restrict a query to torrents with at least one seeder.
     *
     * @param  Builder<Torrent>  $query
     */
    public function scopeAlive(Builder $query): void
    {
        $query->where('seeders', '>', 0);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'));
    }

    /**
     * Get human-readable file size.
     */
    public function sizeForHumans(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Check if the torrent has a stored file.
     */
    public function hasTorrentFile(): bool
    {
        return ! empty($this->torrent_file);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return TorrentFactory::new();
    }
}
