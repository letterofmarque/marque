<?php

declare(strict_types=1);

namespace Marque\Trove\Exceptions;

use RuntimeException;

/**
 * There is no .torrent to give this user. The message is meant for them: it
 * says why, and what to do.
 *
 * Thrown by TorrentFileService::forDownload() when the tracker's download gate
 * refuses (a member whose downloading is paused, #10732), and, as
 * NoAnnounceUrl, when there is no announce URL to write into the file. A
 * frontend catches this once and answers 403 with the message.
 */
class DownloadRefused extends RuntimeException {}
