<?php

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('redirects guests to the login page', function () {
    $this->get(route('generations.index'))->assertRedirect(route('login'));
});

it('lists only the user\'s own generations, newest first, 12 per page', function () {
    $user = User::factory()->create();
    $oldest = Generation::factory()->for($user)->succeeded()->create(['created_at' => now()->subDays(2)]);
    Generation::factory()->for($user)->count(11)->succeeded()->create(['created_at' => now()->subDay()]);
    $newest = Generation::factory()->for($user)->create(['created_at' => now()]);
    Generation::factory()->succeeded()->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 12)
            ->where('generations.data.0.id', $newest->id)
            ->where('generations.meta.total', 13));

    $this->actingAs($user)
        ->get(route('generations.index', ['page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $oldest->id));
});

it('shows what a finished generation cost the user, but not what it cost us', function () {
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/1.png']);

    $this->actingAs($generation->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.data.0', [
                'id' => $generation->id,
                'status' => 'succeeded',
                'prompt' => $generation->prompt,
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_image_count' => 0,
                'estimated_min_charge_nanodollars' => 58_181_250,
                'estimated_max_charge_nanodollars' => 86_325_000,
                'charge_nanodollars' => 69_906_250,
                'charge_waived' => false,
                'failure_message' => null,
                'image_url' => route('generations.image', $generation),
                'image_expired' => false,
                'created_at' => $generation->created_at->toIso8601String(),
                'completed_at' => $generation->completed_at->toIso8601String(),
            ]));
});

it('marks an image as expired once neither our copy nor Pixelforge\'s URL is available', function () {
    $generation = Generation::factory()->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

    $this->actingAs($generation->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true));
});

it('explains each kind of failure in plain words', function (string $errorCode, string $message) {
    $generation = Generation::factory()->failed()->create(['error_code' => $errorCode]);

    $this->actingAs($generation->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.data.0.failure_message', $message)
            ->where('generations.data.0.charge_nanodollars', 0));
})->with([
    'content policy' => ['content_policy_violation', 'This prompt was rejected by the content policy. Try rewording it.'],
    'daily limit' => ['daily_limit_exceeded', "Today's generation limit has been reached. Please try again after midnight UTC."],
    'model could not generate' => ['generation_failed', "The model couldn't create an image for this prompt. Try rephrasing it."],
    'Pixelforge error' => ['internal_error', 'Something went wrong while generating. Please try again.'],
    'never started' => [SubmitGeneration::SUBMISSION_FAILED, "We couldn't start this generation. Please try again."],
    'our own misconfiguration' => ['invalid_api_key', 'Something went wrong on our side. Please try again.'],
]);

it('tells the page to keep refreshing only while a generation is unfinished', function (string $state, bool $hasUnfinished) {
    $generation = Generation::factory()->{$state}()->create();

    $this->actingAs($generation->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasUnfinished', $hasUnfinished));
})->with([
    'pending' => ['pending', true],
    'processing' => ['processing', true],
    'succeeded' => ['succeeded', false],
    'failed' => ['failed', false],
]);
