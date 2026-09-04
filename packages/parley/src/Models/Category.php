<?php

declare(strict_types=1);

namespace Marque\Parley\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marque\Parley\Database\Factories\CategoryFactory;

/**
 * A forum category.
 *
 * Only forum threads have one. Comment threads attach to a model instead, so a
 * deployment running comments-only never creates a category at all.
 */
class Category extends Model
{
    use HasFactory;

    protected $table = 'parley_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<Thread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /**
     * Threads in display order: pinned first, then most recently active.
     *
     * @return HasMany<Thread, $this>
     */
    public function orderedThreads(): HasMany
    {
        return $this->threads()
            ->orderByDesc('pinned')
            ->orderByDesc('created_at');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
