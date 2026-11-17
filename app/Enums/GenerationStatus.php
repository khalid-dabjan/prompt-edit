<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /**
     * Saved locally but not yet accepted by Pixelforge. Never sent by Pixelforge itself.
     */
    case Pending = 'pending';

    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
