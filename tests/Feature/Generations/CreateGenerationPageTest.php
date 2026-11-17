<?php

use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('redirects guests to the login page', function () {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
});

it('shows the generate page with the markup users pay', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generations/Create')
            ->where('markupPercent', 25)
            ->where('prefill', null));
});

it('pre-fills the form from one of the user\'s earlier generations', function () {
    $generation = Generation::factory()->failed()->create([
        'prompt' => 'A lighthouse on a cliff at dusk',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/reference.png'],
    ]);

    $this->actingAs($generation->user)
        ->get(route('generations.create', ['from' => $generation->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', [
            'prompt' => 'A lighthouse on a cliff at dusk',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/reference.png'],
        ]));
});

it('does not pre-fill from another user\'s generation', function () {
    $generation = Generation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('generations.create', ['from' => $generation->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
});
