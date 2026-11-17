<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An inclusive range of UTC days. Like Pixelforge's usage report, it defaults
 * to the last 30 days and allows at most 92.
 */
class ReportRequest extends FormRequest
{
    public const int MAX_DAYS = 92;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->startDate()->diffInDays($this->endDate()) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('end_date', __('The report can cover at most :days days.', ['days' => self::MAX_DAYS]));
                }
            },
        ];
    }

    public function endDate(): CarbonImmutable
    {
        return $this->filled('end_date')
            ? CarbonImmutable::parse($this->string('end_date')->toString(), 'UTC')->startOfDay()
            : CarbonImmutable::now('UTC')->startOfDay();
    }

    public function startDate(): CarbonImmutable
    {
        return $this->filled('start_date')
            ? CarbonImmutable::parse($this->string('start_date')->toString(), 'UTC')->startOfDay()
            : $this->endDate()->subDays(29);
    }
}
