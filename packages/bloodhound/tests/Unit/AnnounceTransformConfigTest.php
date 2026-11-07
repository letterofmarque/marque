<?php

declare(strict_types=1);

// Job #109 step 2: an endpoint names its transform by CLASS, resolved from the
// container, and nothing else is accepted. A config value used as a file to
// run, on the announce path that strangers hit unauthenticated all day, would
// be remote code execution. A string that isn't even a class name never
// reaches the autoloader.

use Illuminate\Http\Request;
use Marque\Bloodhound\Support\AnnounceRouting;
use Marque\Bloodhound\Support\ReshapedRequest;
use Marque\Bloodhound\Tests\LowercaseKeyTransform;
use Marque\Threepio\Support\RawQuery;

it('takes a class implementing AnnounceTransform', function () {
    config(['bloodhound.endpoints' => ['legacy' => ['announce_path' => 'a.php', 'scrape_path' => 's.php', 'transform' => LowercaseKeyTransform::class]]]);

    expect(AnnounceRouting::endpoints()['legacy']->transform)->toBe(LowercaseKeyTransform::class);
});

it('refuses anything else, naming the endpoint', function (mixed $transform) {
    config(['bloodhound.endpoints' => ['legacy' => ['announce_path' => 'a.php', 'scrape_path' => 's.php', 'transform' => $transform]]]);

    expect(fn () => AnnounceRouting::endpoints())->toThrow(InvalidArgumentException::class, 'legacy');
})->with([
    'a file path' => '/var/www/storage/app/transform.php',
    'a relative path' => '../transform.php',
    'a class that does not exist' => 'App\\Migration\\NoSuchTransform',
    'a class that is not a transform' => Request::class,
    'a closure-ish string' => 'strtolower',
    'not a string' => [['App\\X', 'apply']],
]);

it('has no transform when none is named', function () {
    expect(AnnounceRouting::primary()->transform)->toBeNull();
});

describe('ReshapedRequest::withQueryString', function () {
    it('keeps the parsed query and the raw query string in step, binary included', function () {
        $hash = hex2bin(str_repeat('ab', 20));
        $request = Request::create('/announce.php', 'GET', ['passkey' => 'OLD']);

        $reshaped = ReshapedRequest::withQueryString($request, 'passkey=new&info_hash='.urlencode($hash));

        expect($reshaped->query('passkey'))->toBe('new')
            ->and($reshaped->server('QUERY_STRING'))->toBe('passkey=new&info_hash='.urlencode($hash))
            ->and(RawQuery::of($reshaped)->first('info_hash'))->toBe($hash)
            ->and($request->query('passkey'))->toBe('OLD');
    });

    it('keeps the route the request matched', function () {
        $request = Request::create('/announce.php', 'GET');
        $request->setRouteResolver(fn () => 'the route');

        expect(ReshapedRequest::withQueryString($request, 'a=b')->route())->toBe('the route');
    });
});
