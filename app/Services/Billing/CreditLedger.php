<?php

namespace App\Services\Billing;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves credits in and out of a user's balance. Every change is a ledger row
 * plus the same change to users.balance_nanodollars, in one transaction, so
 * the balance always equals the sum of the user's ledger.
 *
 * A generation reserves its highest possible price when submitted (hold),
 * then gets it back and pays the actual price once it is final (settle). A
 * failed generation only gets the hold back, so it costs nothing.
 */
class CreditLedger
{
    /**
     * A fake purchase: the brief asks for a "Buy credits" button without real payments.
     */
    public function purchase(User $user, int $amountNanodollars): CreditTransaction
    {
        return DB::transaction(fn (): CreditTransaction => $this->record(
            $user->id,
            null,
            CreditTransactionType::Purchase,
            $amountNanodollars,
        ));
    }

    /**
     * Reserve the most this generation can cost.
     *
     * @throws InsufficientCreditsException when the balance can't cover it; nothing is reserved.
     */
    public function hold(Generation $generation): void
    {
        $amount = $generation->estimated_max_charge_nanodollars;

        DB::transaction(function () use ($generation, $amount): void {
            // Check and reserve in one statement, so two submissions racing
            // each other can never both spend the same credits.
            $reserved = User::query()
                ->whereKey($generation->user_id)
                ->where('balance_nanodollars', '>=', $amount)
                ->decrement('balance_nanodollars', $amount);

            if ($reserved === 0) {
                throw new InsufficientCreditsException(
                    requiredNanodollars: $amount,
                    balanceNanodollars: (int) User::query()->whereKey($generation->user_id)->value('balance_nanodollars'),
                );
            }

            $this->insert($generation->user_id, $generation->id, CreditTransactionType::Hold, -$amount);
        });
    }

    /**
     * Once a generation is final: give back its hold and record what it actually
     * cost. Safe to call more than once; each entry is recorded at most once.
     */
    public function settle(Generation $generation): void
    {
        if ($generation->user_id === null || ! $generation->status->isFinal()) {
            return;
        }

        DB::transaction(function () use ($generation): void {
            $entries = CreditTransaction::query()
                ->where('generation_id', $generation->id)
                ->get()
                ->keyBy(fn (CreditTransaction $entry): string => $entry->type->value);

            $hold = $entries->get(CreditTransactionType::Hold->value);

            if ($hold !== null && ! $entries->has(CreditTransactionType::HoldRelease->value)) {
                $this->record($generation->user_id, $generation->id, CreditTransactionType::HoldRelease, -$hold->amount_nanodollars);
            }

            if ($generation->charge_nanodollars > 0 && ! $entries->has(CreditTransactionType::Charge->value)) {
                $this->record($generation->user_id, $generation->id, CreditTransactionType::Charge, -$generation->charge_nanodollars);
            }
        });
    }

    private function record(?int $userId, ?int $generationId, CreditTransactionType $type, int $amountNanodollars): CreditTransaction
    {
        $transaction = $this->insert($userId, $generationId, $type, $amountNanodollars);

        User::query()->whereKey($userId)->increment('balance_nanodollars', $amountNanodollars);

        return $transaction;
    }

    private function insert(?int $userId, ?int $generationId, CreditTransactionType $type, int $amountNanodollars): CreditTransaction
    {
        return CreditTransaction::create([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type,
            'amount_nanodollars' => $amountNanodollars,
        ]);
    }
}
