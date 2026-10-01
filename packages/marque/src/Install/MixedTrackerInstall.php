<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use RuntimeException;

/**
 * The app already holds both tracker halves — hound's keyless announce beside
 * bloodhound's authenticated one. marque:install refuses to build on that; one
 * of them has to be removed by hand first.
 */
final class MixedTrackerInstall extends RuntimeException {}
