<?php

namespace App\Enums;

/**
 * Every change to a user's credit balance. Credits are US dollars.
 */
enum CreditTransactionType: string
{
    /**
     * Credits bought by the user (positive).
     */
    case Purchase = 'purchase';

    /**
     * The most a generation can cost, reserved when it is submitted (negative).
     */
    case Hold = 'hold';

    /**
     * The reservation given back once the generation is final (positive).
     */
    case HoldRelease = 'hold_release';

    /**
     * What the finished generation actually cost the user (negative).
     */
    case Charge = 'charge';
}
