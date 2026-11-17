<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('redirects guests to the login page', function () {
    $generation = Generation::factory()->succeeded()->create();

    $this->get(route('generations.image', $generation))->assertRedirect(route('login'));
});

it('serves the stored copy of the image to its owner', function () {
    Storage::fake('local');
    Storage::disk('local')->put('generations/1.png', "\x89PNG-bytes");
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/1.png']);

    $response = $this->actingAs($generation->user)->get(route('generations.image', $generation));

    $response->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
    expect($response->streamedContent())->toBe("\x89PNG-bytes");
});

it('sends the owner to the Pixelforge image until a copy is stored', function () {
    $generation = Generation::factory()->succeeded()->create([
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_A.png?signature=abc',
        'image_url_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($generation->user)
        ->get(route('generations.image', $generation))
        ->assertRedirect('https://pixelforge.promptedit.dev/images/gen_A.png?signature=abc');
});

it('returns 404 when there is no stored copy and the Pixelforge image has expired', function (array $attributes) {
    $generation = Generation::factory()->succeeded()->create($attributes);

    $this->actingAs($generation->user)
        ->get(route('generations.image', $generation))
        ->assertNotFound();
})->with([
    'expired Pixelforge URL' => [['image_url_expires_at' => now()->subMinute()]],
    'no image at all' => [['image_url' => null, 'image_url_expires_at' => null]],
]);

it('returns 404 for another user\'s generation', function () {
    Storage::fake('local');
    Storage::disk('local')->put('generations/1.png', "\x89PNG-bytes");
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/1.png']);

    $this->actingAs(User::factory()->create())
        ->get(route('generations.image', $generation))
        ->assertNotFound();
});
