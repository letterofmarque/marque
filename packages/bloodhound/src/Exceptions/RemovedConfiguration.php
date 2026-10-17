<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Exceptions;

use RuntimeException;

/**
 * A published config sets something bloodhound no longer supports, and
 * ignoring it would change what the tracker does without anyone noticing.
 */
final class RemovedConfiguration extends RuntimeException {}
