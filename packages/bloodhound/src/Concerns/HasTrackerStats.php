<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Concerns;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Number;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Contracts\UserInterface;

/**
 * Provides tracker stats functionality for User models.
 *
 * Handles upload/download tracking and ratio calculation. Announce keys live in
 * announce_keys and are issued by TrackerStatsService.
 */
trait HasTrackerStats
{
    /**
     * Initialize the trait.
     */
    public function initializeHasTrackerStats(): void
    {
        // Deliberately no mergeFillable. It used to add announce_key,
        // uploaded, downloaded and seedtime to the consumer's $fillable,
        // making a credential and the ratio settable from request data — and,
        // on a model relying on $guarded = [], silently restricting
        // mass assignment to those four columns alone (Spec #119). bloodhound
        // writes these through the query builder and TrackerStatsService,
        // neither of which consults $fillable.
        $this->mergeCasts([
            'uploaded' => 'integer',
            'downloaded' => 'integer',
            'seedtime' => 'integer',
            'leechtime' => 'integer',
        ]);
    }

    /**
     * Issue a key when a user is created, or when their address is verified
     * if the app verifies addresses.
     *
     * Written to announce_keys through the tracker's own service, never to
     * users.announce_key, which is deprecated. Read a key back with
     * TrackerStatsInterface::announceKeyFor(), not $user->announce_key.
     *
     * The key methods this trait used to carry (generateAnnounceKey(),
     * regenerateAnnounceKey()) are gone: a key is the tracker's to issue, not
     * something the consumer's model does to itself (Spec #119).
     */
    public static function bootHasTrackerStats(): void
    {
        static::created(function ($model) {
            // An app that verifies addresses gets the key on verification
            // instead (IssueAnnounceKeyOnVerification): a sign-up nobody has
            // proven the address of could be anyone's (usarrs #10879).
            if ($model instanceof MustVerifyEmail && ! $model->hasVerifiedEmail()) {
                return;
            }

            if ($model instanceof UserInterface) {
                app(TrackerStatsInterface::class)->regenerateAnnounceKey($model);
            }
        });

        // Verified without the Verified event: email_verified_at set directly,
        // as an import, an admin screen or a seeder does. Nothing else would
        // ever issue that user a key (#11070). Never rotates an existing one.
        static::updated(function ($model) {
            if (! $model instanceof MustVerifyEmail || ! $model instanceof UserInterface) {
                return;
            }

            if (! $model->wasChanged('email_verified_at') || ! $model->hasVerifiedEmail()) {
                return;
            }

            $tracker = app(TrackerStatsInterface::class);

            if ($tracker->announceKeyFor($model) === null) {
                $tracker->regenerateAnnounceKey($model);
            }
        });
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
     * Format bytes to human-readable string, exactly as the dashboard shows the
     * same figure (Number::fileSize, 1024-based), to two decimals. It used to
     * pick the unit by counting decimal digits, which switched units at a
     * different point near each boundary (#11105).
     */
    private function formatBytes(int $bytes): string
    {
        return Number::fileSize($bytes, 2);
    }
}
