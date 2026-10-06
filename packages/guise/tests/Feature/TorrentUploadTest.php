<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Marque\Guise\Livewire\Torrent\Upload;
use Marque\Guise\Tests\TestUser;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->uploader = TestUser::factory()->uploader()->create();
    Storage::fake('local');
});

function createTestTorrentFile(string $name = 'test'): UploadedFile
{
    // Create a minimal valid bencoded torrent structure
    $info = [
        'name' => $name,
        'piece length' => 262144,
        'pieces' => str_repeat('a', 20), // SHA1 hash is 20 bytes
        'length' => 1000,
    ];

    $torrent = [
        'announce' => 'http://tracker.example.com/announce',
        'info' => $info,
    ];

    $content = bencode($torrent);

    return UploadedFile::fake()->createWithContent('test.torrent', $content);
}

function bencode(mixed $data): string
{
    if (is_int($data)) {
        return "i{$data}e";
    }

    if (is_string($data)) {
        return strlen($data).':'.$data;
    }

    if (is_array($data)) {
        if (array_keys($data) === range(0, count($data) - 1)) {
            $encoded = 'l';
            foreach ($data as $item) {
                $encoded .= bencode($item);
            }

            return $encoded.'e';
        }

        ksort($data);
        $encoded = 'd';
        foreach ($data as $key => $value) {
            $encoded .= bencode((string) $key);
            $encoded .= bencode($value);
        }

        return $encoded.'e';
    }

    throw new InvalidArgumentException('Cannot bencode type: '.gettype($data));
}

test('upload page requires authentication', function () {
    $this->get(route('torrents.upload'))
        ->assertRedirect(route('login'));
});

test('regular user cannot access upload page', function () {
    $this->actingAs($this->user)
        ->get(route('torrents.upload'))
        ->assertForbidden();
});

test('uploader can view upload page', function () {
    $this->actingAs($this->uploader)
        ->get(route('torrents.upload'))
        ->assertOk()
        ->assertSeeLivewire(Upload::class);
});

test('upload page displays form fields', function () {
    $this->actingAs($this->uploader)
        ->get(route('torrents.upload'))
        ->assertOk()
        ->assertSee('Torrent File')
        ->assertSee('Name')
        ->assertSee('Description');
});

test('can upload a torrent file', function () {
    $file = createTestTorrentFile('My Test Torrent');

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', 'My Test Torrent')
        ->set('description', 'A test description')
        ->call('upload')
        ->assertRedirect(route('torrents.index'));

    expect(Torrent::where('name', 'My Test Torrent')->exists())->toBeTrue();
});

test('upload requires a torrent file', function () {
    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('name', 'My Test Torrent')
        ->call('upload')
        ->assertHasErrors(['torrentFile' => 'required']);
});

test('upload requires a name', function () {
    $file = createTestTorrentFile();

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', '')
        ->call('upload')
        ->assertHasErrors(['name' => 'required']);
});

test('description is optional', function () {
    $file = createTestTorrentFile('Optional Desc Test');

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', 'No Description Torrent')
        ->call('upload')
        ->assertRedirect(route('torrents.index'));

    $torrent = Torrent::where('name', 'No Description Torrent')->first();
    expect($torrent)->not->toBeNull()
        ->and($torrent->description)->toBeNull();
});

test('uploaded torrent is associated with authenticated user', function () {
    $file = createTestTorrentFile('User Assoc Test');

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', 'User Association Test')
        ->call('upload');

    $torrent = Torrent::where('name', 'User Association Test')->first();
    expect($torrent->user_id)->toBe($this->uploader->id);
});

// #10947: the tracker's private-flag rule is enforced before anything is stored.
describe('the tracker\'s private-flag rule', function () {
    function guiseUploadPolicy(PrivateFlag $flag): void
    {
        app()->instance(TorrentFilePolicyInterface::class, new class($flag) implements TorrentFilePolicyInterface
        {
            public function __construct(private PrivateFlag $flag) {}

            public function privateFlag(): PrivateFlag
            {
                return $this->flag;
            }

            public function announceUrlFor(?UserInterface $user): ?string
            {
                return 'https://tracker.example/announce';
            }
        });
    }

    test('a refused torrent shows the reason on the file field and is not stored', function () {
        guiseUploadPolicy(PrivateFlag::Require);

        Livewire::actingAs($this->uploader)
            ->test(Upload::class)
            ->set('torrentFile', createTestTorrentFile('Public One'))
            ->set('name', 'Public One')
            ->call('upload')
            ->assertHasErrors(['torrentFile'])
            ->assertSee('private');

        expect(Torrent::count())->toBe(0);
    });

    test('a warning is passed on with the success message', function () {
        guiseUploadPolicy(PrivateFlag::WarnIfPublic);

        Livewire::actingAs($this->uploader)
            ->test(Upload::class)
            ->set('torrentFile', createTestTorrentFile('Warned One'))
            ->set('name', 'Warned One')
            ->call('upload')
            ->assertRedirect(route('torrents.index'));

        expect(session('status'))->toContain('isn\'t marked private');
    });
});

// Job #158 criterion 375: every mode, at this entry point, for both kinds of torrent.
describe('the private-flag rule, mode by mode', function () {
    test('refuses or accepts per mode, never altering an accepted file', function (PrivateFlag $flag, bool $private, ?string $refusalSays, ?string $warningSays) {
        guiseUploadPolicy($flag);
        $info = ['length' => 5, 'name' => 'm', 'piece length' => 16384, 'pieces' => str_repeat('a', 20)] + ($private ? ['private' => 1] : []);
        $bytes = bencode(['announce' => 'http://x/a', 'info' => $info]);

        $component = Livewire::actingAs($this->uploader)
            ->test(Upload::class)
            ->set('torrentFile', UploadedFile::fake()->createWithContent('m.torrent', $bytes))
            ->set('name', 'Mode Test')
            ->call('upload');

        if ($refusalSays !== null) {
            $component->assertHasErrors(['torrentFile'])->assertSee($refusalSays);
            expect(Torrent::count())->toBe(0);

            return;
        }

        $component->assertHasNoErrors()->assertRedirect(route('torrents.index'));
        $torrent = Torrent::sole();
        expect(Storage::disk('local')->get($torrent->torrent_file))->toBe($bytes)
            ->and($torrent->info_hash)->toBe(sha1(Bencode::rawDictionary($bytes)['info']));

        $warningSays === null
            ? expect(session('status'))->toBe('Torrent uploaded successfully.')
            : expect(session('status'))->toContain($warningSays);
    })->with([
        'allow, public' => [PrivateFlag::Allow, false, null, null],
        'allow, private' => [PrivateFlag::Allow, true, null, null],
        'require, public' => [PrivateFlag::Require, false, 'ticked', null],
        'require, private' => [PrivateFlag::Require, true, null, null],
        'disallow, private' => [PrivateFlag::Disallow, true, 'unticked', null],
        'disallow, public' => [PrivateFlag::Disallow, false, null, null],
        'warn_if_public, public' => [PrivateFlag::WarnIfPublic, false, null, 'isn\'t marked private'],
        'warn_if_public, private' => [PrivateFlag::WarnIfPublic, true, null, null],
        'warn_if_private, private' => [PrivateFlag::WarnIfPrivate, true, null, 'is marked private'],
        'warn_if_private, public' => [PrivateFlag::WarnIfPrivate, false, null, null],
    ]);
});
