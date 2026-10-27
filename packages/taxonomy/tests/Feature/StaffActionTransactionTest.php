<?php

declare(strict_types=1);

use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Services\Curator;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;

/**
 * The record is made inside the change's own transaction, so the two stand or
 * fall together: a change rolled back is never recorded, and a store that
 * fails closed (throws, on purpose) takes the change down with it. Shown here
 * with a store that refuses.
 */
it('rolls the change back when the store refuses the record', function () {
    config()->set('taxonomy.definitions.path', __DIR__.'/../fixtures/taxonomies');
    $admin = TestUser::create(['name' => 'a', 'email' => 'tx@example.com', 'password' => 'x', 'role' => 'admin']);

    app()->instance(StaffActionsInterface::class, new class implements StaffActionsInterface
    {
        public function record(StaffAction $action): void
        {
            throw new RuntimeException('store refused');
        }
    });

    expect(fn () => app(Curator::class)->addFacetValue('resolution', '4320p', $admin))->toThrow(RuntimeException::class, 'store refused');

    expect(FacetValue::query()->where('value', '4320p')->exists())->toBeFalse();
});
