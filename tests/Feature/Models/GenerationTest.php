<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('keeps the billing record of a generation when its user deletes their account', function () {
    $generation = Generation::factory()->succeeded()->create();

    $generation->user->delete();

    expect($generation->fresh())
        ->not->toBeNull()
        ->user_id->toBeNull()
        ->charge_nanodollars->toBe(69_906_250)
        ->provider_cost_nanodollars->toBe(55_925_000);
});

it('rejects a second generation with the same Pixelforge id', function () {
    $generation = Generation::factory()->create();

    Generation::factory()->create(['pixelforge_id' => $generation->pixelforge_id]);
})->throws(UniqueConstraintViolationException::class);

it('allows many generations that Pixelforge has not accepted yet', function () {
    $user = User::factory()->create();

    Generation::factory()->pending()->for($user)->count(2)->create();

    expect($user->generations()->whereNull('pixelforge_id')->count())->toBe(2);
});
