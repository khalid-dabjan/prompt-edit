<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Pixelforge webhook event we received. The unique event_id makes a
 * duplicate delivery detectable before it is acted on.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['event_id', 'type', 'pixelforge_generation_id', 'payload', 'processed_at'])]
class WebhookEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
