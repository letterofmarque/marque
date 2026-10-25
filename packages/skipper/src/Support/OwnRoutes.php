<?php

declare(strict_types=1);

namespace Marque\Skipper\Support;

use Illuminate\Http\Request;
use Marque\Skipper\Http\ScreenController;
use Marque\Trove\Registry\AdminScreen;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Whether a screen's package serves it from its own route (#11108).
 *
 * The screen's own URL is its declared path when that has more than one
 * segment (usarrs declares admin/users and binds it), and the path under the
 * panel prefix when it is a single segment, which is how skipper serves it.
 *
 * Answered by asking the router what would answer a GET there, rather than by
 * comparing URIs: that respects the HTTP method (a POST-only route serves no
 * screen) and is the compiled matcher's cheap lookup under route:cache, where
 * building every Route to compare would cost a few microseconds per route on
 * each request.
 *
 * @internal
 */
final class OwnRoutes
{
    public static function urlOf(AdminScreen $screen): string
    {
        $path = trim($screen->path, '/');

        if (str_contains($path, '/')) {
            return '/'.$path;
        }

        $prefix = trim((string) config('skipper.prefix', 'admin'), '/');

        return '/'.ltrim($prefix.'/'.$screen->pathSegment(), '/');
    }

    /**
     * Whether a route other than skipper's own answers GET at the screen's URL.
     */
    public static function servedElsewhere(AdminScreen $screen): bool
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create(self::urlOf($screen), 'GET'));
        } catch (HttpException) {
            return false;
        }

        return $route->getControllerClass() !== ScreenController::class;
    }
}
