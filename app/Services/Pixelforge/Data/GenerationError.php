<?php

namespace App\Services\Pixelforge\Data;

use App\Services\Pixelforge\ErrorCode;

/**
 * Why a generation failed after Pixelforge accepted it.
 */
final readonly class GenerationError
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    /**
     * @param  array{code: string, message: string}  $error
     */
    public static function fromArray(array $error): self
    {
        return new self(code: $error['code'], message: $error['message']);
    }

    /**
     * The known error code, or null when Pixelforge sends one we don't know yet.
     */
    public function errorCode(): ?ErrorCode
    {
        return ErrorCode::tryFrom($this->code);
    }
}
