<?php

declare(strict_types=1);

use Marque\Hound\Services\AnnounceService;

describe('AnnounceService', function () {
    it('can be resolved from container', function () {
        $service = $this->app->make(AnnounceService::class);

        expect($service)->toBeInstanceOf(AnnounceService::class);
    });
});
