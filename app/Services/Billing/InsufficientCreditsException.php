<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * The user's balance can't cover the most a generation could cost.
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNanodollars,
        public readonly int $balanceNanodollars,
    ) {
        parent::__construct('Not enough credits for this generation.');
    }
}
