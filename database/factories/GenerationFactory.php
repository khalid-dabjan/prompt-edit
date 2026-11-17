<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults to a medium 1024x1024 generation that Pixelforge has accepted and queued.
 * Money is in nanodollars and matches Pixelforge's published rates plus the 25% markup.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'pixelforge_id' => 'gen_'.Str::upper((string) Str::ulid()),
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'markup_percent' => 25,
            'estimated_min_charge_nanodollars' => 58_181_250,
            'estimated_max_charge_nanodollars' => 86_325_000,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ];
    }

    /**
     * Saved locally, not yet accepted by Pixelforge.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Pending,
            'pixelforge_id' => null,
            'rates' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    /**
     * Finished with 10 text, 1100 output and 950 reasoning tokens:
     * 55,925,000 nanodollars from Pixelforge, 69,906,250 charged to the user.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 950,
            'provider_cost_nanodollars' => 55_925_000,
            'charge_nanodollars' => 69_906_250,
            'image_url' => 'https://pixelforge.promptedit.dev/images/'.$attributes['pixelforge_id'].'.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now()->subSeconds(20),
            'completed_at' => now(),
        ]);
    }

    /**
     * Failed after Pixelforge accepted it. Failed generations cost nothing.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Failed,
            'provider_cost_nanodollars' => 0,
            'charge_nanodollars' => 0,
            'error_code' => 'internal_error',
            'error_message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
            'started_at' => now()->subSeconds(10),
            'completed_at' => now(),
        ]);
    }
}
