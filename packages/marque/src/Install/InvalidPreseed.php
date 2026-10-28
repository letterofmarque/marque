<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use RuntimeException;

/**
 * A preseed file the installer won't act on: unreadable, malformed, or with a
 * key or value it doesn't recognise. Refused before anything is changed.
 */
class InvalidPreseed extends RuntimeException {}
