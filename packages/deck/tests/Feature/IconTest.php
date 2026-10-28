<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Every icon a Marque package names renders. An unknown name renders nothing,
 * so an icon missing here shows as a blank space in the panel or dashboard,
 * and that's how users, server, tag, user and both of bosun's went unnoticed
 * until the Marque 11.0 docs read-through.
 */
it('renders every icon the packages name', function (string $name) {
    $html = Blade::render('<x-deck::icon :name="$name" />', ['name' => $name]);

    expect($html)->toContain('<svg')->toContain('data-icon="'.$name.'"');
})->with([
    'arrow-left', 'arrow-down-tray', 'magnifying-glass', 'pencil', 'plus',
    // Admin screens and dashboard panels: usarrs, taxonomy, bosun.
    'users', 'server', 'tag', 'user', 'user-group', 'clipboard-document-list',
]);

it('renders nothing for a name it does not ship', function () {
    expect(trim(Blade::render('<x-deck::icon name="unicorn" />')))->toBe('');
});
