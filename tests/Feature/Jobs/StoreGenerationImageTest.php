<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const FAKE_PNG = "\x89PNG\r\n\x1a\n-image-bytes";

it('stores a copy of the image and records where it is', function () {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://pixelforge.test/images/gen_A.png?signature=abc']);
    Http::fake(['pixelforge.test/images/gen_A.png*' => Http::response(FAKE_PNG, 200, ['Content-Type' => 'image/png'])]);

    (new StoreGenerationImage($generation))->handle();

    expect($generation->fresh()->image_path)->toBe("generations/{$generation->id}.png");
    Storage::disk('local')->assertExists("generations/{$generation->id}.png");
    expect(Storage::disk('local')->get("generations/{$generation->id}.png"))->toBe(FAKE_PNG);
});

it('does not download an image it already stored', function () {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/1.png']);
    Http::fake();

    (new StoreGenerationImage($generation))->handle();

    Http::assertNothingSent();
});

it('fails without storing anything when the download is not a PNG', function () {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://pixelforge.test/images/gen_A.png']);
    Http::fake(['pixelforge.test/images/*' => Http::response('<html>Not found</html>', 200)]);

    $job = (new StoreGenerationImage($generation))->withFakeQueueInteractions();
    $job->handle();

    $job->assertFailedWith(RuntimeException::class);
    expect($generation->fresh()->image_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('throws so the job is retried when the download fails', function () {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://pixelforge.test/images/gen_A.png']);
    Http::fake(['pixelforge.test/images/*' => Http::response('Bad Gateway', 502)]);

    (new StoreGenerationImage($generation))->handle();
})->throws(RequestException::class);
