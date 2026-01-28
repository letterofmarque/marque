<?php

declare(strict_types=1);

namespace Marque\Trove\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'file_count' => 'integer',
        ];
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
    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Marque\Trove\Database\Factories\TorrentFactory::new();
    }
}
