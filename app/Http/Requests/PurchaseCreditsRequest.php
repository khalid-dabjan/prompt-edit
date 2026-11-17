<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditsRequest extends FormRequest
{
    /**
     * Only the configured credit packs can be bought.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount_usd' => ['required', 'integer', Rule::in(config()->array('billing.credit_packs_usd'))],
        ];
    }

    public function amountNanodollars(): int
    {
        return $this->integer('amount_usd') * 1_000_000_000;
    }
}
