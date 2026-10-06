<?php

declare(strict_types=1);

namespace Marque\Trove\Services;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;
use Marque\Trove\Exceptions\NoAnnounceUrl;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\UploadInspection;

/**
 * The .torrent file on its way in and on its way out (#10947).
 *
 * The stored file is the uploader's original, and its info dictionary is never
 * modified: the info_hash is sha1 of those raw bytes, and every member's
 * download carries them unchanged, so everyone shares one hash. An upload that
 * breaks the tracker's rules is refused with instructions, never rewritten. A
 * download rebuilds only the top level: the tracker's announce URL for that
 * member, a comment pointing at the torrent's page, and nothing the uploader's
 * client added. announce-list is always stripped: clients prefer it over
 * announce (BEP 12), and an uploader's list can carry their personal announce
 * URL for another site. Cross-seeding is its own package (#10950).
 */
class TorrentFileService
{
    /** Top-level keys a download keeps from the stored file. */
    private const KEPT_KEYS = ['encoding', 'info'];

    public function inspect(string $content): UploadInspection
    {
        try {
            $raw = Bencode::rawDictionary($content);
            $info = isset($raw['info']) ? Bencode::decode($raw['info']) : null;
        } catch (InvalidArgumentException) {
            return new UploadInspection(refusals: ['This isn\'t a valid .torrent file.']);
        }

        if (! is_array($info)) {
            return new UploadInspection(refusals: ['This .torrent has no info dictionary, so it isn\'t a valid torrent.']);
        }

        $refusals = [];
        $warnings = [];

        // v2 (BEP 52) has no SHA-1 info_hash for the tracker to key on; a
        // hybrid has both, and v1 clients announce the v1 hash.
        if (isset($info['meta version']) && ! isset($info['pieces'])) {
            $refusals[] = 'This is a BitTorrent v2-only torrent, which this tracker can\'t serve. Recreate it as v1 (or hybrid) in your client, then upload it again.';
        } elseif (isset($info['meta version'])) {
            $warnings[] = 'This is a hybrid v1/v2 torrent. The tracker works with its v1 info hash, so clients announcing only the v2 hash won\'t be seen.';
        }

        $private = ($info['private'] ?? 0) === 1;

        $rule = $this->policy()?->privateFlag() ?? PrivateFlag::Allow;

        if ($rule === PrivateFlag::Require && ! $private) {
            $refusals[] = 'This tracker only accepts private torrents. Recreate the torrent with the "private" option ticked in your client, then upload it again.';
        }

        if ($rule === PrivateFlag::Disallow && $private) {
            $refusals[] = 'This tracker only accepts public torrents. Recreate the torrent with the "private" option unticked in your client, then upload it again.';
        }

        if ($rule === PrivateFlag::WarnIfPublic && ! $private) {
            $warnings[] = 'This torrent isn\'t marked private, so clients may share its peers outside this tracker (DHT, peer exchange).';
        }

        if ($rule === PrivateFlag::WarnIfPrivate && $private) {
            $warnings[] = 'This torrent is marked private, so clients will only find peers through this tracker.';
        }

        return new UploadInspection($refusals, $warnings);
    }

    /**
     * The .torrent to hand this user.
     *
     * With no tracker policy bound, the stored file as-is. Otherwise the stored
     * info dictionary, byte for byte, under a new top level: announce (the
     * tracker's URL for this user) and comment (the torrent's page, if given).
     *
     * @throws NoAnnounceUrl when the tracker has no announce URL for this user
     */
    public function forDownload(Torrent $torrent, ?UserInterface $user, ?string $comment = null): string
    {
        $stored = (string) Storage::disk(config('trove.storage_disk', 'local'))->get((string) $torrent->torrent_file);
        $policy = $this->policy();

        if ($policy === null) {
            return $stored;
        }

        $announce = $policy->announceUrlFor($user) ?? throw new NoAnnounceUrl;

        $values = array_intersect_key(Bencode::rawDictionary($stored), array_flip(self::KEPT_KEYS));
        $values['announce'] = Bencode::encode($announce);

        if ($comment !== null && $comment !== '') {
            $values['comment'] = Bencode::encode($comment);
        }

        // Bencode wants dictionary keys in raw byte order.
        ksort($values, SORT_STRING);

        $body = 'd';
        foreach ($values as $key => $value) {
            $body .= Bencode::encode((string) $key).$value;
        }

        return $body.'e';
    }

    private function policy(): ?TorrentFilePolicyInterface
    {
        return app()->bound(TorrentFilePolicyInterface::class) ? app(TorrentFilePolicyInterface::class) : null;
    }
}
