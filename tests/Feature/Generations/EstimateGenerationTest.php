<?php

use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

it('rejects guests', function () {
    $this->postJson(route('generations.estimate'), [])->assertUnauthorized();
});

it('returns the price range the user would be quoted on submit', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), [
            'prompt' => 'A lighthouse on a cliff at dusk',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_image_count' => 1,
        ])
        ->assertOk()
        ->assertExactJson([
            'min_charge_nanodollars' => 347_725_000,
            'max_charge_nanodollars' => 457_125_000,
        ]);
});

it('estimates a prompt that has not been written yet', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);

    // No text tokens: 280 output and 0–500 reasoning tokens, plus 25%.
    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), [
            'prompt' => null,
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_image_count' => 0,
        ])
        ->assertOk()
        ->assertExactJson([
            'min_charge_nanodollars' => 14_000_000,
            'max_charge_nanodollars' => 21_812_500,
        ]);
});

it('rejects options Pixelforge does not offer', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), [
            'size' => '512x512',
            'quality' => 'ultra',
            'reference_image_count' => 5,
        ])
        ->assertUnprocessable()
        ->assertInvalid(['size', 'quality', 'reference_image_count']);
});

it('says prices are unavailable when Pixelforge pricing cannot be loaded', function () {
    Exceptions::fake();
    Http::fake(['pixelforge.test/v1/pricing' => Http::response('Service Unavailable', 503)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), [
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_image_count' => 0,
        ])
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Prices are unavailable right now.']);
});
