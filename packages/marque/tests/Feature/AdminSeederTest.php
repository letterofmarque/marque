<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\AdminSeeder;
use Marque\Marque\Tests\TestCase;

/**
 * A tracker with no admin is a tracker nobody can administer.
 *
 * The `role` column defaults to 'user' (trove's add_role_to_users migration)
 * and nothing in the suite ships a command to promote anybody — so on a fresh
 * install the first person to register is a plain user, /admin is unreachable,
 * and the only route to a first admin is tinker or a manual UPDATE.
 *
 * Checked 2026-09-14 after Dan asked whether the install had created one. It
 * had not, and nothing else was going to.
 *
 * **Nobody chooses a password.** The account is seeded with random bytes no
 * one ever sees, and the operator is emailed a reset link. That verifies the
 * address, proves the mailer works, and lets the real password-reset flow set
 * the credential — three jobs from one email, and no plaintext password held
 * anywhere or typed into a terminal.
 */
final class AdminSeederTest extends TestCase
{
    public function test_it_reports_when_no_admin_exists(): void
    {
        $this->assertFalse((new AdminSeeder)->adminExists(fn (): int => 0));
    }

    public function test_it_reports_when_an_admin_already_exists(): void
    {
        $this->assertTrue((new AdminSeeder)->adminExists(fn (): int => 1));
    }

    public function test_it_builds_the_attributes_for_a_new_admin(): void
    {
        $attributes = (new AdminSeeder)->attributesFor('Dan', 'dan@example.test');

        $this->assertSame('Dan', $attributes['name']);
        $this->assertSame('dan@example.test', $attributes['email']);
    }

    public function test_the_password_is_random_and_hashed(): void
    {
        $seeder = new AdminSeeder;

        $first = $seeder->attributesFor('Dan', 'dan@example.test');
        $second = $seeder->attributesFor('Dan', 'dan@example.test');

        // Nobody knows it, nobody needs to. It exists so the column is not
        // null and so the account cannot be signed into until the reset link
        // is used.
        $this->assertNotSame($first['password'], $second['password']);
        $this->assertStringStartsWith('$', $first['password'], 'the password must be hashed');
    }

    public function test_the_admin_is_not_pre_verified(): void
    {
        // The reset email is what verifies the address — a human receives mail
        // at it and clicks through. Stamping email_verified_at here instead
        // would be the installer asserting something it has not observed,
        // which is the habit this whole Build exists to break.
        $attributes = (new AdminSeeder)->attributesFor('Dan', 'dan@example.test');

        $this->assertArrayNotHasKey('email_verified_at', $attributes);
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->assertNotNull((new AdminSeeder)->validate('Dan', 'not-an-email'));
    }

    public function test_it_rejects_an_empty_name(): void
    {
        $this->assertNotNull((new AdminSeeder)->validate('', 'dan@example.test'));
    }

    public function test_it_accepts_sane_details(): void
    {
        $this->assertNull((new AdminSeeder)->validate('Dan', 'dan@example.test'));
    }

    public function test_it_sets_the_role_after_creation_not_through_mass_assignment(): void
    {
        // Found 2026-09-14 against the real clean-room app: the seeded admin
        // landed with role='user'. Laravel 13's stock User model declares
        // #[Fillable] attributes and does NOT list `role`, so mass assignment
        // silently dropped it — producing an "admin" account that is not an
        // admin, which is worse than failing outright.
        //
        // So role is assigned explicitly after create() rather than passed
        // into it, and is deliberately absent from the mass-assignable set.
        $attributes = (new AdminSeeder)->attributesFor('Dan', 'dan@example.test');

        $this->assertArrayNotHasKey('role', $attributes);
    }

    public function test_the_role_is_exposed_separately(): void
    {
        $this->assertSame('admin', (new AdminSeeder)->role());
    }
}
