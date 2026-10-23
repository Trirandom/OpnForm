<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function putAgedFile(string $path, int $hoursAgo): void
{
    Storage::put($path, 'content');
    touch(Storage::path($path), now()->subHours($hoursAgo)->getTimestamp());
}

it('deletes abandoned tmp uploads only once they are old enough', function () {
    Storage::fake();
    putAgedFile('tmp/old-upload', 48);
    putAgedFile('tmp/recent-upload', 1);

    Artisan::call('forms:storage-cleanup');

    expect(Storage::exists('tmp/old-upload'))->toBeFalse();
    expect(Storage::exists('tmp/recent-upload'))->toBeTrue();
});

it('deletes submission files no longer referenced by any submission', function () {
    Storage::fake();
    $user = $this->actingAsUser();
    $form = $this->createForm($user, $this->createUserWorkspace($user));
    $fileFieldId = collect($form->properties)->where('type', 'files')->first()['id'];
    $dir = 'forms/' . $form->id . '/submissions/';

    $kept = 'kept_' . Str::uuid() . '.webp';
    $removedField = 'removed_field_' . Str::uuid() . '.pdf';
    $encoded = 'my file_' . Str::uuid() . '.pdf';
    $orphan = 'replaced_' . Str::uuid() . '.webp';
    $recentOrphan = 'in_progress_' . Str::uuid() . '.webp';

    $form->submissions()->create(['data' => [
        $fileFieldId => [$kept, rawurlencode($encoded)],
        'field-removed-from-form' => $removedField,
    ]]);
    foreach ([$kept, $removedField, $encoded, $orphan] as $file) {
        putAgedFile($dir . $file, 48);
    }
    putAgedFile($dir . $recentOrphan, 1);

    Artisan::call('forms:storage-cleanup', ['--dry-run' => true]);
    expect(Storage::exists($dir . $orphan))->toBeTrue();

    Artisan::call('forms:storage-cleanup');

    expect(Storage::exists($dir . $kept))->toBeTrue();
    expect(Storage::exists($dir . $removedField))->toBeTrue();
    expect(Storage::exists($dir . $encoded))->toBeTrue();
    expect(Storage::exists($dir . $recentOrphan))->toBeTrue();
    expect(Storage::exists($dir . $orphan))->toBeFalse();
});

it('deletes the files of a form without any submission left', function () {
    Storage::fake();
    putAgedFile('forms/999999/submissions/leftover.webp', 48);

    Artisan::call('forms:storage-cleanup');

    expect(Storage::exists('forms/999999/submissions/leftover.webp'))->toBeFalse();
});

it('deletes form assets no longer referenced anywhere', function () {
    Storage::fake();
    $user = $this->actingAsUser();
    $workspace = $this->createUserWorkspace($user);

    $logo = 'logo_' . Str::uuid() . '.webp';
    $inDescription = 'my image_' . Str::uuid() . '.webp';
    $inTrashedForm = 'trashed_' . Str::uuid() . '.webp';
    $orphan = 'orphan_' . Str::uuid() . '.png';
    $recentOrphan = 'recent_' . Str::uuid() . '.png';

    $this->createForm($user, $workspace, [
        'logo_picture' => route('forms.assets.show', [$logo]),
        'description' => '<p><img src="' . route('forms.assets.show', [rawurlencode($inDescription)]) . '"></p>',
    ]);
    $this->createForm($user, $workspace, [
        'cover_picture' => route('forms.assets.show', [$inTrashedForm]),
    ])->delete();

    foreach ([$logo, $inDescription, $inTrashedForm, $orphan] as $file) {
        putAgedFile('assets/forms/' . $file, 24 * 8);
    }
    putAgedFile('assets/forms/' . $recentOrphan, 48);

    Artisan::call('forms:storage-cleanup', ['--dry-run' => true]);
    expect(Storage::exists('assets/forms/' . $orphan))->toBeTrue();

    Artisan::call('forms:storage-cleanup');

    expect(Storage::exists('assets/forms/' . $logo))->toBeTrue();
    expect(Storage::exists('assets/forms/' . $inDescription))->toBeTrue();
    expect(Storage::exists('assets/forms/' . $inTrashedForm))->toBeTrue();
    expect(Storage::exists('assets/forms/' . $recentOrphan))->toBeTrue();
    expect(Storage::exists('assets/forms/' . $orphan))->toBeFalse();
});
