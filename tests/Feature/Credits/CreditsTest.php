<?php

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('redirects guests to the login page', function () {
    $this->get(route('credits.index'))->assertRedirect(route('login'));
    $this->post(route('credits.store'), ['amount_usd' => 10])->assertRedirect(route('login'));
});

it('adds a credit pack to the balance without taking a payment', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('credits.index'))
        ->post(route('credits.store'), ['amount_usd' => 10])
        ->assertRedirect(route('credits.index'))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->balance_nanodollars)->toBe(10_000_000_000)
        ->and($user->creditTransactions()->sole())
        ->type->toBe(CreditTransactionType::Purchase)
        ->amount_nanodollars->toBe(10_000_000_000);
});

it('only sells the configured credit packs', function (mixed $amount) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.store'), ['amount_usd' => $amount])
        ->assertInvalid('amount_usd');

    expect($user->fresh()->balance_nanodollars)->toBe(0);
})->with([
    'not a pack' => [7],
    'negative' => [-10],
    'missing' => [null],
]);

it('shows the user\'s own credit history, newest first', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('credits.store'), ['amount_usd' => 5]);
    $this->actingAs($user)->post(route('credits.store'), ['amount_usd' => 25]);
    CreditTransaction::create(['user_id' => User::factory()->create()->id, 'type' => CreditTransactionType::Purchase, 'amount_nanodollars' => 1]);

    $this->actingAs($user->fresh())
        ->get(route('credits.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('credits/Index')
            ->where('packsUsd', [5, 10, 25])
            ->where('balanceNanodollars', 30_000_000_000)
            ->has('transactions.data', 2)
            ->where('transactions.data.0.amount_nanodollars', 25_000_000_000)
            ->where('transactions.data.0.type', 'purchase'));
});

it('names the generation behind each generation-related entry', function () {
    $generation = Generation::factory()->create(['prompt' => 'A lighthouse']);
    CreditTransaction::create(['user_id' => $generation->user_id, 'generation_id' => $generation->id, 'type' => CreditTransactionType::Charge, 'amount_nanodollars' => -5]);

    $this->actingAs($generation->user)
        ->get(route('credits.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('transactions.data.0.generation', ['id' => $generation->id, 'prompt' => 'A lighthouse']));
});
