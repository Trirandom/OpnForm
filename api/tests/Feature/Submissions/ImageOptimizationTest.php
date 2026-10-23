<?php

use App\Models\Forms\FormSubmission;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function fakeImage(string $format, int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    if ($format === 'png') {
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 127));
    } else {
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
    }
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 95);
    return ob_get_clean();
}

function putTmpFile(string $content): string
{
    $uuid = (string) Str::uuid();
    Storage::put('tmp/' . $uuid, $content);
    return $uuid;
}

function submitFile($test, $form, string $value): FormSubmission
{
    $formData = $test->generateFormSubmissionData($form);
    $fileFieldId = collect($form->properties)->where('type', 'files')->first()['id'];
    $formData[$fileFieldId] = [$value];
    $test->postJson(route('forms.answer', $form->slug), $formData)->assertSuccessful();

    return FormSubmission::latest('id')->first();
}

it('converts submitted jpeg images to resized webp', function () {
    Storage::fake();
    $user = $this->actingAsUser();
    $form = $this->createForm($user, $this->createUserWorkspace($user));

    $uuid = putTmpFile(fakeImage('jpeg', 1440, 1080));
    $submission = submitFile($this, $form, 'photo_' . $uuid . '.jpg');

    $fileFieldId = collect($form->properties)->where('type', 'files')->first()['id'];
    $storedName = $submission->data[$fileFieldId][0];
    expect($storedName)->toBe('photo_' . $uuid . '.webp');

    $path = 'forms/' . $form->id . '/submissions/' . $storedName;
    expect(Storage::exists($path))->toBeTrue();
    expect(Storage::exists('tmp/' . $uuid))->toBeFalse();
    [$width, $height, $type] = getimagesizefromstring(Storage::get($path));
    expect($width)->toBe(720);
    expect($height)->toBe(540);
    expect($type)->toBe(IMAGETYPE_WEBP);
});

it('keeps transparency when converting png to webp', function () {
    Storage::fake();
    $user = $this->actingAsUser();
    $form = $this->createForm($user, $this->createUserWorkspace($user));

    $uuid = putTmpFile(fakeImage('png', 400, 300));
    $submission = submitFile($this, $form, 'capture_' . $uuid . '.png');

    $fileFieldId = collect($form->properties)->where('type', 'files')->first()['id'];
    $path = 'forms/' . $form->id . '/submissions/' . $submission->data[$fileFieldId][0];
    $image = imagecreatefromstring(Storage::get($path));
    expect(imagesx($image))->toBe(400); // never upscaled
    expect((imagecolorat($image, 0, 0) >> 24) & 0x7F)->toBeGreaterThan(0);
});

it('stores non image and broken image files untouched', function (string $extension, string $content) {
    Storage::fake();
    $user = $this->actingAsUser();
    $form = $this->createForm($user, $this->createUserWorkspace($user));

    $uuid = putTmpFile($content);
    $submission = submitFile($this, $form, 'file_' . $uuid . '.' . $extension);

    $fileFieldId = collect($form->properties)->where('type', 'files')->first()['id'];
    $storedName = $submission->data[$fileFieldId][0];
    expect($storedName)->toBe('file_' . $uuid . '.' . $extension);
    expect(Storage::get('forms/' . $form->id . '/submissions/' . $storedName))->toBe($content);
})->with([
    'pdf' => ['pdf', '%PDF-1.4 fake pdf'],
    'broken jpeg' => ['jpg', 'not really a jpeg'],
]);

it('optimizes existing submission images with the command', function () {
    Storage::fake();
    $user = $this->actingAsUser();
    $form = $this->createForm($user, $this->createUserWorkspace($user));
    $fileFieldId = collect($form->properties)->where('type', 'files')->first()['id'];

    $oldName = 'old_' . Str::uuid() . '.jpg';
    Storage::put('forms/' . $form->id . '/submissions/' . $oldName, fakeImage('jpeg', 1440, 1440));
    $submission = $form->submissions()->create(['data' => [$fileFieldId => [$oldName]]]);

    Artisan::call('forms:optimize-submission-images', ['--dry-run' => true]);
    expect($submission->fresh()->data[$fileFieldId][0])->toBe($oldName);

    Artisan::call('forms:optimize-submission-images');
    $newName = $submission->fresh()->data[$fileFieldId][0];
    expect($newName)->toEndWith('.webp');
    expect(Storage::exists('forms/' . $form->id . '/submissions/' . $oldName))->toBeFalse();
    [$width] = getimagesizefromstring(Storage::get('forms/' . $form->id . '/submissions/' . $newName));
    expect($width)->toBe(720);
});

it('converts uploaded form assets to webp with the assets max width', function () {
    Storage::fake();
    $this->actingAsUser();

    $uuid = putTmpFile(fakeImage('jpeg', 2400, 600));
    $response = $this->postJson(route('open.forms.assets.upload'), ['url' => 'cover_' . $uuid . '.jpg'])
        ->assertSuccessful();

    expect($response->json('url'))->toEndWith('cover_' . $uuid . '.webp');
    $path = 'assets/forms/cover_' . $uuid . '.webp';
    expect(Storage::exists($path))->toBeTrue();
    expect(Storage::exists('tmp/' . $uuid))->toBeFalse();
    [$width, $height] = getimagesizefromstring(Storage::get($path));
    expect($width)->toBe(1920);
    expect($height)->toBe(480);
});

it('gives each submission its own copy of a prefilled form asset', function () {
    Storage::fake();
    $user = $this->actingAsUser();
    $form = $this->createForm($user, $this->createUserWorkspace($user));
    $fileFieldId = collect($form->properties)->where('type', 'files')->first()['id'];

    $assetName = 'default_' . Str::uuid() . '.pdf';
    Storage::put('assets/forms/' . $assetName, '%PDF-1.4 default file');
    $assetUrl = route('forms.assets.show', [$assetName]);

    $first = submitFile($this, $form, $assetUrl);
    $second = submitFile($this, $form, $assetUrl);

    $firstName = $first->data[$fileFieldId][0];
    $secondName = $second->data[$fileFieldId][0];
    expect($firstName)->not->toBe($secondName);
    expect($firstName)->toStartWith('default_')->toEndWith('.pdf');
    expect(Storage::exists('assets/forms/' . $assetName))->toBeTrue();

    $first->delete();
    expect(Storage::exists('forms/' . $form->id . '/submissions/' . $firstName))->toBeFalse();
    expect(Storage::get('forms/' . $form->id . '/submissions/' . $secondName))->toBe('%PDF-1.4 default file');
});
