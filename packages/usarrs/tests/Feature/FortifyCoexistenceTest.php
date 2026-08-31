<?php

declare(strict_types=1);

// Fortify's own routes must never be reachable in a usarrs app — usarrs is the
// only thing allowed to register /login, /register, etc. This is the actual
// fix for job #10583: auth_driver=invite_only correctly 404s usarrs' own
// Register component, but Fortify's independently-registered POST /register
// stayed live underneath it. If Fortify::ignoreRoutes() is called, Fortify's
// own routes never bind in the first place, regardless of auth_driver.
//
// usarrs itself only registers GET /register and GET /login (Livewire
// full-page components; the form submits via wire:submit, not a plain POST
// to the same route) — so once Fortify's competing POST route is gone, a
// direct POST to these URLs 405s (usarrs owns the path, wrong method) rather
// than 404ing or, worse, being handled by Fortify's controller. A 405 here is
// stronger proof than a 404 would be: it shows the URL is owned by usarrs and
// nothing else is listening on POST for it.

test('fortify does not register its own register route', function () {
    $this->post('/register', [
        'name' => 'Evil User',
        'email' => 'evil@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertMethodNotAllowed();
});

test('fortify does not register its own login route', function () {
    $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ])->assertMethodNotAllowed();
});

test('fortify routes stay suppressed even under invite_only', function () {
    config()->set('usarrs.auth_driver', 'invite_only');

    $this->post('/register', [
        'name' => 'Evil User',
        'email' => 'evil@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertMethodNotAllowed();
});
