<?php

declare(strict_types=1);

// #10793: with admin.enabled false, usarrs registers no admin screens and no
// Admin nav entry. The old test of this asserted the flag was TRUE and pointed
// at a test group that didn't exist, so neither guard was tested.

use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Trove\Registry\NavRegistry;

it('registers none of usarrs\' admin screens', function () {
    $screens = array_keys(app(AdminScreenRegistry::class)->all());

    expect(array_filter($screens, fn (string $id): bool => str_starts_with($id, 'usarrs-')))->toBe([]);
});

it('does not advertise Admin in the nav', function () {
    expect(app(NavRegistry::class)->find('usarrs-admin'))->toBeNull();
});

it('still registers the rest of the nav', function () {
    expect(app(NavRegistry::class)->all())->not->toBe([]);
});
