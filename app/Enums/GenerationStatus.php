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

    /**
     * How far along the lifecycle this status is. A generation only ever
     * moves to a later stage, so an event carrying an earlier (or the same)
     * stage is stale and must be ignored.
     */
    public function stage(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    /**
     * Succeeded and failed are final: a generation never leaves them.
     */
    public function isFinal(): bool
    {
        return $this->stage() === self::Succeeded->stage();
    }
}
