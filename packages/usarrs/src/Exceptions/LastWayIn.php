<?php

declare(strict_types=1);

namespace Marque\Usarrs\Exceptions;

use RuntimeException;

/**
 * Rolls back a removal of a connection that would leave the member no way to
 * sign in (Profile\ConnectedAccounts, Job #355).
 */
final class LastWayIn extends RuntimeException {}
