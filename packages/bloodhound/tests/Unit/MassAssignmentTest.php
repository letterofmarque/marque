<?php

declare(strict_types=1);

// Build #113 CP5 (Spec #119 criterion 9). Same rule as parley's
// MassAssignmentTest, which CONTRIBUTING.md says belongs in every package that
// ships models: a package cannot assume the host app calls Model::unguard(),
// and must not assume it does not.
//
// bloodhound was the one package with models and no such test, and the one
// where the exposed columns are credentials. HasTrackerStats used to
// mergeFillable(['announce_key', 'uploaded', 'downloaded', 'seedtime']) into
// the CONSUMER's User model, so an ordinary User::create($request->all())
// let a user set their own announce key and upload figure.

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Tests\FillableTrackerUser;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Bloodhound\Tests\TrackerUser;

/** @return list<class-string<Model>> */
function bloodhoundModels(): array
{
    return [AnnounceKey::class, AnnounceLog::class, LedgerCursor::class, TorrentUser::class];
}

describe('bloodhound\'s own models', function () {
    it('never ships a model that guards nothing', function () {
        foreach (bloodhoundModels() as $class) {
            expect((new $class)->getGuarded())->toBe(['*']);
        }
    });

    // A credential. Written by TrackerStatsService with forceFill and by
    // nothing else, so nothing about it is fillable at all.
    it('refuses to mass-assign an announce key', function () {
        new AnnounceKey(['user_id' => 1, 'key' => str_repeat('a', 32)]);
    })->throws(MassAssignmentException::class);
});

describe('HasTrackerStats on a consumer\'s User model', function () {
    it('adds no tracker column to the model\'s fillable', function () {
        expect((new FillableTrackerUser)->getFillable())->toBe(['name', 'email', 'password']);
    });

    // The request-shaped attack: a registration or profile form posting
    // fields it should not have.
    it('ignores tracker fields in a request-shaped create()', function () {
        $user = FillableTrackerUser::create([
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'password',
            'uploaded' => 999_999_999,
            'downloaded' => 0,
            'seedtime' => 999_999,
            'announce_key' => 'chosenkeychosenkeychosenkeychose',
        ]);

        $row = TestUser::find($user->id);

        expect($row->uploaded)->toBe(0)
            ->and($row->seedtime)->toBe(0)
            ->and($row->announce_key)->toBeNull();
    });

    it('ignores tracker fields in a request-shaped update()', function () {
        $user = FillableTrackerUser::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'password']);

        $user->update(['name' => 'Renamed', 'uploaded' => 999_999_999, 'downloaded' => 0]);

        $row = TestUser::find($user->id);

        expect($row->name)->toBe('Renamed')
            ->and($row->uploaded)->toBe(0);
    });

    // The other half of the same bug. A non-empty $fillable makes Laravel
    // accept ONLY what is listed, so on a model that relied on
    // $guarded = [] the trait's mergeFillable silently dropped name, email
    // and every other attribute from create(). Found in CP #658.
    it('leaves a $guarded = [] model able to mass-assign its own columns', function () {
        $user = TrackerUser::create(['name' => 'Guarded Nothing', 'email' => 'open@example.com', 'password' => 'password']);

        expect(TestUser::find($user->id)->name)->toBe('Guarded Nothing');
    });
});
