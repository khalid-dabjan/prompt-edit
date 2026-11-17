<?php

namespace App\Http\Requests;

use App\Enums\GenerationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The envelope of a Pixelforge webhook event. Its signature is verified
 * before this runs (VerifyPixelforgeSignature).
 */
class PixelforgeWebhookRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'type' => ['required', 'string'],
            'data.object' => [Rule::requiredIf($this->isGenerationEvent()), 'array'],
            'data.object.id' => [Rule::requiredIf($this->isGenerationEvent()), 'string'],
            'data.object.status' => [Rule::requiredIf($this->isGenerationEvent()), Rule::enum(GenerationStatus::class)],
        ];
    }

    /**
     * generation.processing, generation.succeeded and generation.failed carry
     * the full generation object; other events (e.g. ping) may not.
     */
    public function isGenerationEvent(): bool
    {
        return str_starts_with($this->string('type')->toString(), 'generation.');
    }
}
