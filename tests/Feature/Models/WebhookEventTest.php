<?php

use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects recording the same Pixelforge event twice', function () {
    $event = [
        'event_id' => 'evt_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'type' => 'generation.succeeded',
        'pixelforge_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'payload' => ['id' => 'evt_01JC2Z8Q4N6V0M3H7X5T9R2B1K'],
    ];

    WebhookEvent::create($event);
    WebhookEvent::create($event);
})->throws(UniqueConstraintViolationException::class);
