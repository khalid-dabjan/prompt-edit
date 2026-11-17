<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;

const PIXELFORGE_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

function applySnapshot(Generation $generation, App\Services\Pixelforge\Data\Generation $snapshot): Generation
{
    return app(ApplyGenerationSnapshot::class)->handle($generation, $snapshot)->fresh();
}

describe('moving forward', function () {
    it('records when processing started', function () {
        $generation = Generation::factory()->create(['pixelforge_id' => PIXELFORGE_ID]);

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-queued', [
            'status' => 'processing',
            'started_at' => '2026-11-04T14:03:13Z',
        ]));

        expect($generation)
            ->status->toBe(GenerationStatus::Processing)
            ->started_at->toDateTimeString()->toBe('2026-11-04 14:03:13')
            ->charge_nanodollars->toBeNull();
    });

    it('charges the actual cost plus markup once the generation succeeds', function () {
        $generation = Generation::factory()->processing()->create(['pixelforge_id' => PIXELFORGE_ID]);

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-succeeded'));

        // 12 text, 765 image, 6,450 output and 3,184 reasoning tokens at the published rates.
        expect($generation)
            ->status->toBe(GenerationStatus::Succeeded)
            ->input_text_tokens->toBe(12)
            ->input_image_tokens->toBe(765)
            ->output_tokens->toBe(6450)
            ->reasoning_tokens->toBe(3184)
            ->provider_cost_nanodollars->toBe(305_510_000)
            ->charge_nanodollars->toBe(381_887_500)
            ->charge_waived->toBeFalse()
            ->image_url->toStartWith('https://pixelforge.promptedit.dev/images/')
            ->image_url_expires_at->toDateTimeString()->toBe('2026-11-05 14:03:29')
            ->completed_at->toDateTimeString()->toBe('2026-11-04 14:03:29');
    });

    it('charges the markup the user was quoted, not the one configured now', function () {
        config(['billing.markup_percent' => 99]);
        $generation = Generation::factory()->processing()->create(['pixelforge_id' => PIXELFORGE_ID, 'markup_percent' => 50]);

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-succeeded'));

        expect($generation->charge_nanodollars)->toBe(458_265_000);
    });

    it('prices usage at the rates Pixelforge fixed on the generation', function () {
        $generation = Generation::factory()->processing()->create(['pixelforge_id' => PIXELFORGE_ID]);
        $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '12.50'];

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-succeeded', ['rates' => $rates]));

        // Output tokens now cost $80 per 1M: 6,450 × 80,000 nanodollars more than at $40.
        expect($generation)
            ->rates->toBe($rates)
            ->provider_cost_nanodollars->toBe(563_510_000);
    });

    it('records a failure at no cost', function () {
        $generation = Generation::factory()->processing()->create(['pixelforge_id' => PIXELFORGE_ID]);

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-failed', ['id' => PIXELFORGE_ID]));

        expect($generation)
            ->status->toBe(GenerationStatus::Failed)
            ->provider_cost_nanodollars->toBe(0)
            ->charge_nanodollars->toBe(0)
            ->error_code->toBe('internal_error')
            ->error_message->toContain('You were not charged');
    });

    it('records the Pixelforge id when an event arrives before the create response', function () {
        $generation = Generation::factory()->pending()->create();

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-queued', [
            'status' => 'processing',
            'metadata' => ['generation_id' => (string) $generation->id],
        ]));

        expect($generation)
            ->pixelforge_id->toBe(PIXELFORGE_ID)
            ->status->toBe(GenerationStatus::Processing)
            ->rates->toBe(pixelforgeFixture('generation-queued')['rates']);
    });
});

describe('duplicates and out-of-order events', function () {
    it('charges a generation only once when its success is reported twice', function () {
        $generation = Generation::factory()->processing()->create(['pixelforge_id' => PIXELFORGE_ID]);

        applySnapshot($generation, pixelforgeSnapshot('generation-succeeded'));
        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-succeeded', [
            'usage' => ['input_text_tokens' => 99, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 8000, 'total_tokens' => 15314],
        ]));

        expect($generation)
            ->reasoning_tokens->toBe(3184)
            ->charge_nanodollars->toBe(381_887_500);
    });

    it('ignores a status older than the one already recorded', function (string $current, string $late) {
        $generation = Generation::factory()->create(['pixelforge_id' => PIXELFORGE_ID, 'status' => $current]);

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-queued', ['status' => $late]));

        expect($generation->status->value)->toBe($current);
    })->with([
        'queued after processing' => ['processing', 'queued'],
        'processing repeated' => ['processing', 'processing'],
    ]);

    it('never moves a generation out of a final status', function (string $finalState, string $fixture, GenerationStatus $expected) {
        $generation = Generation::factory()->{$finalState}()->create(['pixelforge_id' => PIXELFORGE_ID]);
        $before = $generation->fresh()->only(['status', 'charge_nanodollars', 'provider_cost_nanodollars', 'error_code']);

        $generation = applySnapshot($generation, pixelforgeSnapshot($fixture, ['id' => PIXELFORGE_ID]));

        expect($generation->status)->toBe($expected)
            ->and($generation->only(['status', 'charge_nanodollars', 'provider_cost_nanodollars', 'error_code']))->toBe($before);
    })->with([
        'processing after succeeded' => ['succeeded', 'generation-queued', GenerationStatus::Succeeded],
        'failed after succeeded' => ['succeeded', 'generation-failed', GenerationStatus::Succeeded],
        'succeeded after failed' => ['failed', 'generation-succeeded', GenerationStatus::Failed],
    ]);

    it('ignores a snapshot of a different Pixelforge generation', function () {
        $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_OTHER']);

        $generation = applySnapshot($generation, pixelforgeSnapshot('generation-succeeded'));

        expect($generation)
            ->status->toBe(GenerationStatus::Processing)
            ->charge_nanodollars->toBeNull();
    });
});

describe('when Pixelforge reports on a generation we gave up submitting', function () {
    beforeEach(function () {
        $this->generation = Generation::factory()->pending()->create();
        (new SubmitGeneration($this->generation))->failed(new RuntimeException('Retries exhausted.'));
    });

    it('shows the image and records our cost, but does not charge the user', function () {
        $generation = applySnapshot($this->generation, pixelforgeSnapshot('generation-succeeded', [
            'metadata' => ['generation_id' => (string) $this->generation->id],
        ]));

        expect($generation)
            ->status->toBe(GenerationStatus::Succeeded)
            ->pixelforge_id->toBe(PIXELFORGE_ID)
            ->image_url->not->toBeNull()
            ->provider_cost_nanodollars->toBe(305_510_000)
            ->charge_nanodollars->toBe(0)
            ->charge_waived->toBeTrue()
            ->error_code->toBeNull();
    });

    it('keeps the charge waived when Pixelforge reports processing before success', function () {
        $metadata = ['metadata' => ['generation_id' => (string) $this->generation->id]];

        applySnapshot($this->generation, pixelforgeSnapshot('generation-queued', ['status' => 'processing'] + $metadata));
        applySnapshot($this->generation, pixelforgeSnapshot('generation-queued', $metadata));
        $generation = applySnapshot($this->generation, pixelforgeSnapshot('generation-succeeded', $metadata));

        expect($generation)
            ->status->toBe(GenerationStatus::Succeeded)
            ->charge_nanodollars->toBe(0)
            ->charge_waived->toBeTrue();
    });

    it('records the failure Pixelforge reports, still at no cost', function () {
        $generation = applySnapshot($this->generation, pixelforgeSnapshot('generation-failed', [
            'metadata' => ['generation_id' => (string) $this->generation->id],
        ]));

        expect($generation)
            ->status->toBe(GenerationStatus::Failed)
            ->error_code->toBe('internal_error')
            ->charge_nanodollars->toBe(0);
    });
});

it('keeps a generation Pixelforge itself rejected failed', function () {
    $generation = Generation::factory()->failed()->create(['pixelforge_id' => null, 'error_code' => 'content_policy_violation']);

    $generation = applySnapshot($generation, pixelforgeSnapshot('generation-succeeded', [
        'metadata' => ['generation_id' => (string) $generation->id],
    ]));

    expect($generation)
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('content_policy_violation')
        ->charge_nanodollars->toBe(0);
});

describe('finding the generation for a snapshot', function () {
    it('finds it by Pixelforge id', function () {
        $generation = Generation::factory()->create(['pixelforge_id' => PIXELFORGE_ID]);

        expect(app(ApplyGenerationSnapshot::class)->findGenerationFor(pixelforgeSnapshot('generation-queued')))
            ->toBeInstanceOf(Generation::class)
            ->id->toBe($generation->id);
    });

    it('finds it by the generation id we sent in its metadata', function () {
        $generation = Generation::factory()->pending()->create();

        $found = app(ApplyGenerationSnapshot::class)->findGenerationFor(pixelforgeSnapshot('generation-queued', [
            'metadata' => ['generation_id' => (string) $generation->id],
        ]));

        expect($found?->id)->toBe($generation->id);
    });

    it('does not match metadata pointing at a generation linked to another Pixelforge id', function () {
        $generation = Generation::factory()->create(['pixelforge_id' => 'gen_OTHER']);

        $found = app(ApplyGenerationSnapshot::class)->findGenerationFor(pixelforgeSnapshot('generation-queued', [
            'metadata' => ['generation_id' => (string) $generation->id],
        ]));

        expect($found)->toBeNull();
    });

    it('finds nothing for a generation we do not know', function () {
        expect(app(ApplyGenerationSnapshot::class)->findGenerationFor(pixelforgeSnapshot('generation-queued', ['metadata' => []])))->toBeNull();
    });
});
