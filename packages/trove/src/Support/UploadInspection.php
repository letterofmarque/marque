<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

/**
 * What the tracker makes of an uploaded .torrent, before anything is stored.
 */
final readonly class UploadInspection
{
    /**
     * @param  list<string>  $refusals  Each a reason the upload can't be accepted, naming the fix.
     * @param  list<string>  $warnings  Accepted, but worth telling the uploader.
     */
    public function __construct(
        public array $refusals = [],
        public array $warnings = [],
    ) {}

    public function refused(): bool
    {
        return $this->refusals !== [];
    }
}
