<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Carbon\CarbonImmutable;
use Marque\Trove\Contracts\TrackerSessionsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Support\TrackerSession;
use RuntimeException;

/**
 * Stands in for a tracker's live sessions. usarrs must not depend on
 * bloodhound, so the screen is proven against the contract alone.
 */
class FakeTrackerSessions implements TrackerSessionsInterface
{
    /** @var array<string, array{owner: int, session: TrackerSession}> */
    public array $sessions = [];

    public bool $busy = false;

    public function add(UserInterface $user, string $torrentName, bool $seeding = true, string $ip = '10.0.0.1'): TrackerSession
    {
        $id = (count($this->sessions) + 1).':'.bin2hex(random_bytes(10));

        $this->sessions[$id] = [
            'owner' => $user->getAuthIdentifier(),
            'session' => new TrackerSession($id, count($this->sessions) + 1, $torrentName, 'qBittorrent/4.5.0', $ip, $seeding, CarbonImmutable::now(), 0, 0),
        ];

        return $this->sessions[$id]['session'];
    }

    public function sessionsFor(UserInterface $user): array
    {
        return array_values(array_map(
            fn (array $entry) => $entry['session'],
            array_filter($this->sessions, fn (array $entry) => $entry['owner'] === $user->getAuthIdentifier()),
        ));
    }

    public function endSession(UserInterface $user, string $sessionId): bool
    {
        if ($this->busy) {
            throw new RuntimeException('That session is busy with an announce right now; try again in a moment.');
        }

        if (($this->sessions[$sessionId]['owner'] ?? null) !== $user->getAuthIdentifier()) {
            return false;
        }

        unset($this->sessions[$sessionId]);

        return true;
    }
}
