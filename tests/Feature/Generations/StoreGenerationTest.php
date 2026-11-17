<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Exceptions\PixelforgeApiException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationInput(array $overrides = []): array
{
    return array_merge([
        'prompt' => 'A lighthouse on a cliff at dusk',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/reference.png'],
    ], $overrides);
}

it('redirects guests to the login page', function () {
    $this->post(route('generations.store'), generationInput())
        ->assertRedirect(route('login'));
});

it('records a pending generation with the quoted price range and queues it for submission', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), generationInput())
        ->assertRedirect(route('generations.index'))
        ->assertSessionHasNoErrors();

    $generation = $user->generations()->sole();

    // 31 characters estimate 8 text tokens (6–10), plus 765 image, 6,450 output and 1,000–8,000 reasoning tokens.
    expect($generation)
        ->status->toBe(GenerationStatus::Pending)
        ->pixelforge_id->toBeNull()
        ->prompt->toBe('A lighthouse on a cliff at dusk')
        ->size->toBe(ImageSize::Landscape)
        ->quality->toBe(ImageQuality::High)
        ->reference_images->toBe(['https://example.com/reference.png'])
        ->markup_percent->toBe(25)
        ->estimated_min_charge_nanodollars->toBe(347_725_000)
        ->estimated_max_charge_nanodollars->toBe(457_125_000)
        ->charge_nanodollars->toBeNull();

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generation->is($generation));
});

it('quotes and records the markup configured when the user submits', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    config(['billing.markup_percent' => 50]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), generationInput());

    expect($user->generations()->sole())
        ->markup_percent->toBe(50)
        ->estimated_min_charge_nanodollars->toBe(417_270_000)
        ->estimated_max_charge_nanodollars->toBe(548_550_000);
});

it('rejects input Pixelforge would refuse', function (array $input, string $invalidField) {
    Queue::fake([SubmitGeneration::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generationInput($input))
        ->assertInvalid($invalidField);

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNotPushed(SubmitGeneration::class);
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'prompt over 4,000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'more than 4 reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'reference image that is not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'reference image that is not http(s)' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);

it('tells the user generation is unavailable when the price cannot be quoted', function () {
    Queue::fake([SubmitGeneration::class]);
    Exceptions::fake();
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/pricing' => Http::response('Service Unavailable', 503)]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generationInput())
        ->assertSessionHasErrors('generation');

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNotPushed(SubmitGeneration::class);
    Exceptions::assertReported(PixelforgeApiException::class);
});

it('asks a user who submits more than 10 generations a minute to wait', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    $user = User::factory()->create();

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->actingAs($user)->post(route('generations.store'), generationInput())->assertSessionHasNoErrors();
    }

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), generationInput())
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors(['generation' => 'You are generating very quickly. Please wait a minute and try again.']);

    expect($user->generations()->count())->toBe(10);
});
