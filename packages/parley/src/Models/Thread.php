<?php

declare(strict_types=1);

namespace Marque\Parley\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Marque\Parley\Database\Factories\ThreadFactory;

/**
 * A discussion.
 *
 * The same model is a torrent's comment thread, a forum thread, and an
 * announcement — they differ in which columns are set, not in type. See the
 * migration for the shape of each.
 *
 * @property int $id
 * @property string|null $threadable_type
 * @property int|null $threadable_id
 * @property int|null $category_id
 * @property string|null $title
 * @property int $user_id
 * @property bool $pinned
 * @property bool $locked
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Thread extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'parley_threads';

    protected $fillable = [
        'threadable_type',
        'threadable_id',
        'category_id',
        'title',
        'user_id',
        'pinned',
        'locked',
    ];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'locked' => 'boolean',
        ];
    }

    /**
     * Whatever this discussion is attached to — a torrent, a request, anything
     * a consumer points it at. Null for a standalone forum thread.
     *
     * @return MorphTo<Model, $this>
     */
    public function threadable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Resolved through trove's config rather than hard-coded, because the user
     * model belongs to the host app. Same approach as trove's own Torrent.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'));
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Only the top-level posts. Replies hang off these via reply_to_id, and the
     * renderer walks down from here rather than flattening the whole thread.
     *
     * @return HasMany<Post, $this>
     */
    public function rootPosts(): HasMany
    {
        return $this->posts()->whereNull('reply_to_id');
    }

    /**
     * A comment thread hangs off something; a forum thread stands alone.
     */
    public function isComments(): bool
    {
        return $this->threadable_type !== null;
    }

    public function isForum(): bool
    {
        return $this->threadable_type === null;
    }

    /**
     * @param  Builder<Thread>  $query
     */
    public function scopeComments(Builder $query): void
    {
        $query->whereNotNull('threadable_type');
    }

    /**
     * @param  Builder<Thread>  $query
     */
    public function scopeForum(Builder $query): void
    {
        $query->whereNull('threadable_type');
    }

    /**
     * Display order for a listing: pinned to the top, newest first after that.
     *
     * @param  Builder<Thread>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('pinned')->orderByDesc('created_at');
    }

    protected static function newFactory(): ThreadFactory
    {
        return ThreadFactory::new();
    }
}
