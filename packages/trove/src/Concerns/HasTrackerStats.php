<?php

declare(strict_types=1);

namespace Marque\Trove\Concerns;

use Illuminate\Support\Str;

/**
 * Provides tracker stats functionality for User models.
 *
 * Handles upload/download tracking, ratio calculation, and passkey management.
 */
trait HasTrackerStats
{
    /**
     * Initialize the trait.
     */
    public function initializeHasTrackerStats(): void
    {
        $this->mergeFillable(['passkey', 'uploaded', 'downloaded', 'seedtime']);

        $this->mergeCasts([
            'uploaded' => 'integer',
            'downloaded' => 'integer',
            'seedtime' => 'integer',
        ]);
    }

    /**
     * Boot the trait.
     */
    public static function bootHasTrackerStats(): void
    {
        static::creating(function ($model) {
            if (empty($model->passkey)) {
                $model->passkey = $model->generatePasskey();
            }
        });
    }

    /**
     * Generate a new passkey.
     */
    public function generatePasskey(): string
    {
        return Str::random(32);
    }

    /**
     * Regenerate the user's passkey.
     */
    public function regeneratePasskey(): string
    {
        $this->passkey = $this->generatePasskey();
        $this->save();

        return $this->passkey;
    }

    /**
     * Get the user's ratio.
     *
     * Returns null if no downloads (infinite ratio).
     */
    public function getRatio(): ?float
    {
        if ($this->downloaded === 0) {
            return null; // Infinite ratio
        }

        return round($this->uploaded / $this->downloaded, 2);
    }

    /**
     * Get ratio as a formatted string.
     */
    public function getRatioForHumans(): string
    {
        $ratio = $this->getRatio();

        if ($ratio === null) {
            return 'Inf';
        }

        return number_format($ratio, 2);
    }

    /**
     * Get uploaded amount formatted for humans.
     */
    public function getUploadedForHumans(): string
    {
        return $this->formatBytes($this->uploaded);
    }

    /**
     * Get downloaded amount formatted for humans.
     */
    public function getDownloadedForHumans(): string
    {
        return $this->formatBytes($this->downloaded);
    }

    /**
     * Get seeding time formatted for humans.
     */
    public function getSeedtimeForHumans(): string
    {
        $seconds = $this->seedtime;

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);

            return "{$minutes}m";
        }

        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return "{$hours}h {$minutes}m";
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);

        return "{$days}d {$hours}h";
    }

    /**
     * Check if user meets minimum ratio requirement.
     */
    public function meetsRatioRequirement(float $minRatio): bool
    {
        $ratio = $this->getRatio();

        // No downloads means infinite ratio - always passes
        if ($ratio === null) {
            return true;
        }

        return $ratio >= $minRatio;
    }

    /**
     * Check if user meets minimum seedtime requirement.
     */
    public function meetsSeedtimeRequirement(int $minSeconds): bool
    {
        return $this->seedtime >= $minSeconds;
    }

    /**
     * Format bytes to human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor] ?? 'B');
    }
}
