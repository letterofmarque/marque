<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\FooterItem;
use Marque\Trove\Registry\FooterItemRegistry;
use Marque\Trove\Tests\TestUser;

// #11100: an app adds to the footer without publishing and copying deck's view.
describe('FooterItemRegistry', function () {
    it('registers and enumerates items', function () {
        $registry = new FooterItemRegistry;
        $registry->register(new FooterItem('build', 'app::footer.build'));

        expect($registry->all())->toHaveCount(1)
            ->and($registry->all()['build']->view)->toBe('app::footer.build');
    });

    it('orders items by position then identifier', function () {
        $registry = new FooterItemRegistry;
        $registry->register(new FooterItem('zebra', 'z', position: 10));
        $registry->register(new FooterItem('apple', 'a', position: 50));
        $registry->register(new FooterItem('mango', 'm', position: 10));

        expect(array_keys($registry->all()))->toBe(['mango', 'zebra', 'apple']);
    });

    it('refuses a duplicate identifier', function () {
        $registry = new FooterItemRegistry;
        $registry->register(new FooterItem('build', 'a'));

        $registry->register(new FooterItem('build', 'b'));
    })->throws(InvalidArgumentException::class, 'build');

    it('finds an item by identifier, or returns null', function () {
        $registry = new FooterItemRegistry;
        $registry->register(new FooterItem('build', 'app::footer.build'));

        expect($registry->find('build')?->view)->toBe('app::footer.build')
            ->and($registry->find('nope'))->toBeNull();
    });

    it('refuses an empty identifier or view', function () {
        expect(fn () => new FooterItem(' ', 'v'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new FooterItem('id', ''))->toThrow(InvalidArgumentException::class);
    });

    // The footer is on guest pages too, so unlike the dashboard a null user is
    // a viewer to answer for, not someone who never sees it.
    it('shows an item with no visibility rule to everyone, guests included', function () {
        $registry = new FooterItemRegistry;
        $registry->register(new FooterItem('legal', 'app::footer.legal'));

        expect($registry->visibleTo(null))->toHaveKey('legal');
    });

    it('asks the visibility closure about the viewer', function () {
        $owner = TestUser::factory()->create();
        $other = TestUser::factory()->create();
        $registry = new FooterItemRegistry;
        $registry->register(new FooterItem('build', 'app::footer.build', visible: fn (?object $user): bool => $user?->id === $owner->id));

        expect($registry->visibleTo($owner))->toHaveKey('build')
            ->and($registry->visibleTo($other))->toBe([])
            ->and($registry->visibleTo(null))->toBe([]);
    });

    it('gates forRole on a minimum role, and never shows it to a guest', function () {
        $registry = new FooterItemRegistry;
        $registry->register(FooterItem::forRole('mod', 'app::footer.mod', Role::Moderator));

        expect($registry->visibleTo(TestUser::factory()->create(['role' => Role::Moderator])))->toHaveKey('mod')
            ->and($registry->visibleTo(TestUser::factory()->create(['role' => Role::User])))->toBe([])
            ->and($registry->visibleTo(null))->toBe([]);
    });

    it('shows verifiedOnly to a verified user only, then asks its own closure', function () {
        $unverified = new class implements MustVerifyEmail
        {
            public function hasVerifiedEmail(): bool
            {
                return false;
            }

            public function markEmailAsVerified(): bool
            {
                return true;
            }

            public function markEmailAsUnverified(): bool
            {
                return true;
            }

            public function sendEmailVerificationNotification(): void {}

            public function getEmailForVerification(): string
            {
                return 'u@example.com';
            }
        };

        $item = FooterItem::verifiedOnly('stats', 'app::footer.stats', visible: fn (?object $user): bool => $user instanceof TestUser);

        expect($item->isVisibleTo(TestUser::factory()->create()))->toBeTrue()
            ->and($item->isVisibleTo($unverified))->toBeFalse()
            ->and($item->isVisibleTo(null))->toBeFalse()
            ->and($item->isVisibleTo(new stdClass))->toBeFalse();
    });
});
