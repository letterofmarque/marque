<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\InstallAnswers;
use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Tests\TestCase;

/**
 * What the interview decided, carried into the fresh process that finishes the
 * install (Job #131). The process that ran composer require can't load the
 * packages it just installed, so everything after it runs in a new one — and
 * nothing the operator answered may be lost on the way.
 */
final class InstallAnswersTest extends TestCase
{
    public function test_a_private_selection_with_extras_survives_the_trip(): void
    {
        $answers = new InstallAnswers(PackageSelection::private()->withApi()->withAdminPanel(), 'Ada', 'ada@example.com');

        $back = InstallAnswers::decode($answers->encode());

        $this->assertTrue($back->selection->isPrivate());
        $this->assertSame($answers->selection->packages(), $back->selection->packages());
        $this->assertSame('Ada', $back->adminName);
        $this->assertSame('ada@example.com', $back->adminEmail);
    }

    public function test_a_public_selection_with_no_admin_survives_the_trip(): void
    {
        $back = InstallAnswers::decode((new InstallAnswers(PackageSelection::public()->withForums(), null, null))->encode());

        $this->assertFalse($back->selection->isPrivate());
        $this->assertContains('marque/parley', $back->selection->packages());
        $this->assertNull($back->adminName);
        $this->assertNull($back->adminEmail);
    }

    public function test_it_refuses_a_payload_naming_a_package_the_installer_does_not_offer(): void
    {
        // The payload is a command-line argument; it must not become a way to
        // composer-wire arbitrary packages.
        $this->expectException(\InvalidArgumentException::class);

        InstallAnswers::decode(base64_encode(json_encode(['private' => true, 'extras' => ['evil/package'], 'admin_name' => null, 'admin_email' => null])));
    }
}
