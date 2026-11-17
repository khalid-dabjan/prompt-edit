<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the live price estimate needs while the user is still typing: the
 * prompt may be empty, and reference images only matter by count.
 */
class EstimateGenerationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_image_count' => ['required', 'integer', 'min:0', 'max:4'],
        ];
    }
}
