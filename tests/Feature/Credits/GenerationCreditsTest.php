<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\CreditTransactionType;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\StoreGenerationImage;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\InsufficientCreditsException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A pending generation (max price 86,325,000 nanodollars) whose maximum price is already held.
 */
function heldGeneration(int $balance = 1_000_000_000): Generation
{
    $user = User::factory()->withBalance($balance)->create();
    $generation = Generation::factory()->for($user)->pending()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    app(CreditLedger::class)->hold($generation);

    return $generation;
}

/**
 * @return array<string, int>
 */
function ledgerFor(Generation $generation): array
{
    return $generation->user->creditTransactions()
        ->where('generation_id', $generation->id)
        ->pluck('amount_nanodollars', 'type')
        ->all();
}

function balanceMatchesLedger(User $user): bool
{
    return $user->fresh()->balance_nanodollars === (int) $user->creditTransactions()->sum('amount_nanodollars') + 1_000_000_000;
}

beforeEach(function () {
    Queue::fake([SubmitGeneration::class, StoreGenerationImage::class]);
});

it('reserves the highest possible price when a generation is submitted', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    $user = User::factory()->withBalance(1_000_000_000)->create();

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse on a cliff at dusk',
        'size' => ImageSize::Landscape->value,
        'quality' => ImageQuality::High->value,
        'reference_images' => ['https://example.com/reference.png'],
    ])->assertSessionHasNoErrors();

    $generation = $user->generations()->sole();
    expect(ledgerFor($generation))->toBe(['hold' => -457_125_000])
        ->and($user->fresh()->balance_nanodollars)->toBe(1_000_000_000 - 457_125_000);
});

it('refuses a generation the user cannot afford at its highest possible price', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    // The quote is 347,725,000–457,125,000: enough for the lowest price, not the highest.
    $user = User::factory()->withBalance(400_000_000)->create();

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse on a cliff at dusk',
            'size' => ImageSize::Landscape->value,
            'quality' => ImageQuality::High->value,
            'reference_images' => ['https://example.com/reference.png'],
        ])
        ->assertSessionHasErrors(['generation' => "You don't have enough credits for this generation. Buy credits to continue."]);

    expect($user->generations()->count())->toBe(0)
        ->and($user->creditTransactions()->count())->toBe(0)
        ->and($user->fresh()->balance_nanodollars)->toBe(400_000_000);
    Queue::assertNotPushed(SubmitGeneration::class);
});

it('never lets two generations spend the same credits', function () {
    $user = User::factory()->withBalance(100_000_000)->create();
    [$first, $second] = Generation::factory()->for($user)->pending()->count(2)->create();

    app(CreditLedger::class)->hold($first);

    expect(fn () => app(CreditLedger::class)->hold($second))->toThrow(InsufficientCreditsException::class)
        ->and($user->fresh()->balance_nanodollars)->toBe(100_000_000 - 86_325_000);
});

it('gives the hold back and charges the actual price when the generation succeeds', function () {
    $generation = heldGeneration();

    app(ApplyGenerationSnapshot::class)->handle($generation, pixelforgeSnapshot('generation-succeeded'));

    // The docs' succeeded generation: 305,510,000 from Pixelforge, 381,887,500 charged.
    expect(ledgerFor($generation))->toBe([
        'hold' => -86_325_000,
        'hold_release' => 86_325_000,
        'charge' => -381_887_500,
    ])->and($generation->user->fresh()->balance_nanodollars)->toBe(1_000_000_000 - 381_887_500)
        ->and(balanceMatchesLedger($generation->user))->toBeTrue();
});

it('charges once when success is reported twice', function () {
    $generation = heldGeneration();

    app(ApplyGenerationSnapshot::class)->handle($generation, pixelforgeSnapshot('generation-succeeded'));
    app(ApplyGenerationSnapshot::class)->handle($generation, pixelforgeSnapshot('generation-succeeded'));
    app(CreditLedger::class)->settle($generation->fresh());

    expect($generation->user->creditTransactions()->where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($generation->user->fresh()->balance_nanodollars)->toBe(1_000_000_000 - 381_887_500);
});

it('gives the whole hold back when the generation fails', function () {
    $generation = heldGeneration();

    app(ApplyGenerationSnapshot::class)->handle($generation, pixelforgeSnapshot('generation-failed', ['id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']));

    expect(ledgerFor($generation))->toBe(['hold' => -86_325_000, 'hold_release' => 86_325_000])
        ->and($generation->user->fresh()->balance_nanodollars)->toBe(1_000_000_000);
});

it('gives the whole hold back when Pixelforge rejects the submission', function () {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(
        ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.']],
        400,
    )]);

    (new SubmitGeneration($generation))->withFakeQueueInteractions()->handle(app(PixelforgeClient::class), app(ApplyGenerationSnapshot::class));

    expect($generation->user->fresh()->balance_nanodollars)->toBe(1_000_000_000)
        ->and(ledgerFor($generation))->toBe(['hold' => -86_325_000, 'hold_release' => 86_325_000]);
});

it('does not charge for a generation it promised was free, even if Pixelforge later finishes it', function () {
    $generation = heldGeneration();
    (new SubmitGeneration($generation))->failed(new RuntimeException('Retries exhausted.'));

    app(ApplyGenerationSnapshot::class)->handle($generation, pixelforgeSnapshot('generation-succeeded', [
        'metadata' => ['generation_id' => (string) $generation->id],
    ]));

    expect(ledgerFor($generation))->toBe(['hold' => -86_325_000, 'hold_release' => 86_325_000])
        ->and($generation->user->fresh()->balance_nanodollars)->toBe(1_000_000_000);
});
