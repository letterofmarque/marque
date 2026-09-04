<?php

declare(strict_types=1);

namespace Marque\Parley\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Marque\Parley\Database\Factories\PostFactory;
use Marque\SquidInk\SquidInk;

/**
 * A message in a thread.
 *
 * Bodies are stored as source text plus the name of the parser that wrote them,
 * never as rendered HTML. Rendering happens through squidink on read, so a post
 * written in BBCode and a torrent description written in Markdown come out of
 * the same pipeline with the same safety guarantees.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $user_id
 * @property string $body
 * @property string $body_format
 * @property int|null $reply_to_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Post extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'parley_posts';

    protected $fillable = [
        'thread_id',
        'user_id',
        'body',
        'body_format',
        'reply_to_id',
    ];

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'));
    }

    /**
     * The post this one answers, if any.
     *
     * @return BelongsTo<Post, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'reply_to_id');
    }

    /**
     * Direct replies. Nesting is arbitrary, so walking this recursively is what
     * builds a subtree — the depth limit is a rendering decision, not a data one.
     *
     * @return HasMany<Post, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Post::class, 'reply_to_id');
    }

    public function isReply(): bool
    {
        return $this->reply_to_id !== null;
    }

    /**
     * The parser this body was written with.
     *
     * Falls back to the configured default, then to squidink's own, so a row
     * written before a site declared a parser still renders.
     */
    public function format(): string
    {
        return $this->body_format
            ?? config('parley.format.parser')
            ?? app(SquidInk::class)->defaultParser();
    }

    /**
     * The body as HTML.
     *
     * Rendering is squidink's job entirely — parley neither parses nor
     * sanitises. What comes back is safe because only nodes the schema declared
     * can exist, not because anything here stripped them.
     */
    public function renderedBody(): string
    {
        return app(SquidInk::class)->convert($this->body, $this->format(), 'html');
    }

    /**
     * The body as plain text — for search indexing, notifications, excerpts.
     */
    public function plainBody(): string
    {
        return app(SquidInk::class)->convert($this->body, $this->format(), 'text');
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }
}
